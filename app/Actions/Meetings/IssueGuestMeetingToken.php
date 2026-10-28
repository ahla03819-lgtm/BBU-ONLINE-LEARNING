<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingParticipantRole;
use App\Models\GuestMeetingSession;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use App\Services\LiveKit\IssuedMeetingToken;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Issues a least-privilege room token for an already-admitted guest only. */
final class IssueGuestMeetingToken
{
    public function __construct(
        private LiveKitTokenIssuer $issuer,
        private GuestMeetingSessionService $sessions,
    ) {}

    /** @return array{token: IssuedMeetingToken, participant: MeetingParticipant, lifecycle_version: int} */
    public function handle(GuestMeetingSession $session): array
    {
        [$participant, $meeting, $reservedByThisIssue] = DB::transaction(function () use ($session) {
            // EndMeeting locks the meeting before its guest sessions. Matching
            // that order prevents an end/token race from deadlocking.
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($session->meeting_id);
            $locked = GuestMeetingSession::query()
                ->with(['invitation', 'participant.attendanceSessions'])
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($locked->meeting_id !== $meeting->id || $locked->meeting_id !== $locked->invitation?->meeting_id) {
                abort(404);
            }
            $locked->setRelation('meeting', $meeting);

            if (! $this->sessions->canJoinActiveMeeting($locked)) {
                abort(403);
            }

            $participant = $locked->participant;
            if ($participant && ($participant->meeting_id !== $meeting->id || $participant->user_id !== null || $participant->removed_at !== null)) {
                abort(403);
            }

            $alreadyOccupiesSlot = $participant && (
                $participant->join_reserved_until?->isFuture()
                || $participant->attendanceSessions()->whereNull('left_at')->exists()
            );
            if (! $alreadyOccupiesSlot) {
                $occupied = MeetingParticipant::query()
                    ->where('meeting_id', $meeting->id)
                    ->where(function ($query) {
                        $query->where('join_reserved_until', '>', now())
                            ->orWhereHas('attendanceSessions', fn ($sessions) => $sessions->whereNull('left_at'));
                    })->count();
                if ($occupied >= $meeting->max_participants) {
                    throw ValidationException::withMessages(['meeting' => 'This meeting has reached capacity.']);
                }
            }

            // Do not leave a linked participant row when capacity denies this
            // issuance. The row represents a technical room identity only after
            // a guest is allowed to reserve or occupy a seat.
            if (! $participant) {
                $participant = MeetingParticipant::query()->create([
                    'meeting_id' => $meeting->id,
                    'user_id' => null,
                    'display_name_snapshot' => $locked->display_name_snapshot,
                    'role' => MeetingParticipantRole::Guest,
                ]);
                $locked->update(['meeting_participant_id' => $participant->id]);
            }

            $reservedByThisIssue = ! $alreadyOccupiesSlot;
            if ($reservedByThisIssue) {
                $participant->update(['join_reserved_until' => now()->addSeconds((int) config('livekit.token_ttl_seconds', 300))]);
            }

            return [$participant, $meeting, $reservedByThisIssue];
        });

        try {
            $issued = $this->issuer->issue(
                $meeting->livekit_room_name,
                $participant->livekit_identity,
                $participant->display_name_snapshot,
                ['camera', 'microphone'],
                json_encode(['participant_type' => 'guest'], JSON_THROW_ON_ERROR),
                canPublishData: false,
            );
        } catch (Throwable $exception) {
            if ($reservedByThisIssue) {
                MeetingParticipant::query()->whereKey($participant->id)->lockForUpdate()->update(['join_reserved_until' => null]);
            }
            throw $exception;
        }

        return ['token' => $issued, 'participant' => $participant, 'lifecycle_version' => $meeting->lifecycle_version];
    }
}
