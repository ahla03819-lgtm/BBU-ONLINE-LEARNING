<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingScreenShareRequestStatus;
use App\Events\MeetingParticipantRemoved;
use App\Events\MeetingScreenShareRequestChanged;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use App\Services\LiveKit\LiveKitRoomManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RemoveMeetingParticipant
{
    public function __construct(
        private LiveKitRoomManager $rooms,
        private AuditLogger $audit,
        private GuestMeetingSessionService $guestSessions,
    ) {}

    public function handle(User $actor, MeetingParticipant $participant, ?string $reason): MeetingParticipant
    {
        if ($participant->removed_at) {
            Gate::forUser($actor)->authorize('removeParticipant', [$participant->meeting]);
            $removed = $participant;
        } else {
            Gate::forUser($actor)->authorize('remove', $participant);
            $removed = DB::transaction(function () use ($actor, $participant, $reason) {
                // EndMeeting and guest token issuance lock the meeting first.
                // Taking it before the participant prevents an end/remove race
                // from holding participant and guest-session locks in reverse.
                $meeting = Meeting::query()->lockForUpdate()->findOrFail($participant->meeting_id);
                $guestSession = $this->guestSessions->lockForParticipant($participant->id);
                $locked = MeetingParticipant::query()->lockForUpdate()->findOrFail($participant->id);
                abort_unless($locked->meeting_id === $meeting->id, 404);
                $locked->setRelation('meeting', $meeting);
                if ($locked->removed_at) {
                    Gate::forUser($actor)->authorize('removeParticipant', [$locked->meeting]);

                    return $locked;
                }
                Gate::forUser($actor)->authorize('remove', $locked);
                if ($locked->meeting->host_user_id === $locked->user_id) {
                    throw ValidationException::withMessages(['participant' => 'The assigned meeting host cannot be removed.']);
                }
                $locked->update(['removed_at' => now(), 'removed_by' => $actor->id, 'removal_reason' => $reason, 'join_reserved_until' => null]);
                // This affects only the guest session linked to this exact
                // server-owned participant; normal User sessions are untouched.
                $guestSession = $this->guestSessions->invalidateForParticipant($locked, $guestSession);
                $locked->setRelation('guestMeetingSession', $guestSession);
                $locked->screenShareRequests()->whereNotNull('active_slot')->get()->each(function ($request) {
                    $request->update(['status' => MeetingScreenShareRequestStatus::Cancelled, 'active_slot' => null, 'completed_at' => now()]);
                    MeetingScreenShareRequestChanged::dispatch($request);
                });
                $this->audit->log('meeting.participant-removed', $locked, [], ['meeting_id' => $locked->meeting_id, 'reason' => $reason]);
                MeetingParticipantRemoved::dispatch($locked);

                return $locked;
            });
        }

        // Guest revocation creates its own durable, after-commit reconciliation
        // record. Keep the established immediate path for authenticated users.
        $guestSession = $removed->relationLoaded('guestMeetingSession')
            ? $removed->getRelation('guestMeetingSession')
            : $removed->guestMeetingSession()->first();
        if ($removed->removed_at && ! $guestSession) {
            $state = $this->rooms->removeParticipant($removed->meeting->livekit_room_name, $removed->livekit_identity);
            if ($state->value === 'unknown') {
                $this->audit->log('meeting.participant-removal-provider-pending', $removed, [], ['meeting_id' => $removed->meeting_id]);
            }
        }

        return $removed;
    }
}
