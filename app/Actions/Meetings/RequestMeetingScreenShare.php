<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingProviderState;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Events\MeetingScreenShareRequestChanged;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\MeetingScreenShareRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRoomManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Handles the Student's "Share" click. This only ever CREATES a Pending request
 * for a Host/Teacher to decide; it never grants LiveKit screen-share sources.
 * The only provider call in this path is a REVOKE, used to clear a lapsed
 * approval so its grant cannot linger, and it runs outside every transaction.
 */
final class RequestMeetingScreenShare
{
    public function __construct(
        private LiveKitRoomManager $rooms,
        private AuditLogger $audit,
        private EnsureCurrentMeetingAttendance $attendance,
    ) {}

    public function handle(User $actor, Meeting $meeting): MeetingScreenShareRequest
    {
        $participant = MeetingParticipant::query()
            ->where('meeting_id', $meeting->id)
            ->where('user_id', $actor->id)
            ->firstOrFail();

        // Cheap structural gate first: no provider call is made for a request
        // that could never be authorised anyway.
        Gate::forUser($actor)->authorize('requestScreenShareStructure', [$meeting, $participant]);

        // Current provider presence. Contains the only network I/O on this path
        // and runs before any request row is locked.
        $current = $this->attendance->ensure($actor, $meeting, $participant);

        // Defence in depth: the session must belong to this participant, still be
        // open, and carry the SID the provider just confirmed. A stale row for a
        // different SID must never authorise a share request.
        $session = $current->session;
        if ($session->meeting_participant_id !== $participant->id || ! $current->isCurrentFor($session)) {
            throw ValidationException::withMessages(['screen_share' => 'You must be present in the meeting to request screen sharing.']);
        }

        Gate::forUser($actor)->authorize('requestScreenShare', [$meeting, $participant]);

        // Revoke of a lapsed approval. Outside the transaction so provider I/O
        // never runs while a row lock is held. It only withdraws a permission the
        // Student already had; it never grants anything.
        $this->releaseLapsedApproval($meeting, $participant);

        return DB::transaction(function () use ($actor, $meeting, $participant) {
            // One active request per participant is enforced in application code
            // because active_slot is nullable and NULLs do not collide in a
            // unique index.
            $active = MeetingScreenShareRequest::query()
                ->where('meeting_participant_id', $participant->id)
                ->where('active_slot', 1)
                ->lockForUpdate()
                ->first();
            if ($active) {
                return $active;
            }

            $request = MeetingScreenShareRequest::query()->create([
                'meeting_id' => $meeting->id,
                'meeting_participant_id' => $participant->id,
                'requester_user_id' => $actor->id,
                'status' => MeetingScreenShareRequestStatus::Pending,
                'active_slot' => 1,
                'requested_at' => now(),
            ]);
            $this->audit->log('meeting.screen-share-requested', $request, [], ['meeting_id' => $meeting->id]);
            // After-commit broadcast: no client can observe a Pending request
            // that was not committed.
            MeetingScreenShareRequestChanged::dispatch($request);

            return $request;
        });
    }

    /**
     * An approval that lapsed before it was ever used still holds a provider
     * grant. It is reclaimed here so a fresh request cannot inherit it. An
     * approval that already started a share is never treated as stale.
     *
     * Fails closed: if the grant cannot be withdrawn the request is refused
     * rather than left dangling. Only ever called with false, and only ever for
     * this participant, so Host/Teacher sharing is untouched.
     */
    private function releaseLapsedApproval(Meeting $meeting, MeetingParticipant $participant): void
    {
        $lapsed = MeetingScreenShareRequest::query()
            ->where('meeting_participant_id', $participant->id)
            ->where('active_slot', 1)
            ->get()
            ->first(fn (MeetingScreenShareRequest $request) => $request->status === MeetingScreenShareRequestStatus::Approved
                && ! $request->started_at
                && $request->expires_at?->isPast());
        if (! $lapsed) {
            return;
        }

        if ($this->rooms->setParticipantScreenSharePermission($meeting->livekit_room_name, $participant->livekit_identity, false) === MeetingProviderState::Unknown) {
            throw ValidationException::withMessages(['screen_share' => 'Unable to reset the previous screen sharing approval.']);
        }

        DB::transaction(function () use ($lapsed) {
            $locked = MeetingScreenShareRequest::query()->lockForUpdate()->find($lapsed->id);
            if (! $locked || $locked->active_slot !== 1
                || $locked->status !== MeetingScreenShareRequestStatus::Approved
                || $locked->started_at
                || ! $locked->expires_at?->isPast()) {
                return;
            }
            $locked->update([
                'status' => MeetingScreenShareRequestStatus::Expired,
                'active_slot' => null,
                'completed_at' => now(),
                'expires_at' => null,
            ]);
            MeetingScreenShareRequestChanged::dispatch($locked);
        });
    }
}
