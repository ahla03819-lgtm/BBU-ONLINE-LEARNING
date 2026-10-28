<?php

namespace App\Services\GuestMeetings;

use App\Enums\GuestMeetingSessionStatus;
use App\Enums\MeetingStatus;
use App\Models\GuestMeetingInvitation;
use App\Models\GuestMeetingSession;
use App\Models\MeetingParticipant;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class GuestMeetingSessionService
{
    public function __construct(
        private GuestMeetingInvitationService $invitations,
        private GuestParticipantRemovalService $removals,
    ) {}

    public function create(GuestMeetingInvitation $invitation, string $displayName, ?string $retryCredential = null): IssuedGuestMeetingSession
    {
        return DB::transaction(function () use ($invitation, $displayName, $retryCredential) {
            $locked = GuestMeetingInvitation::query()->with('meeting')->lockForUpdate()->findOrFail($invitation->id);
            if (! $this->invitations->isAvailable($locked)) {
                abort(404);
            }

            // The cookie is scoped to this invitation's submit URL. Reusing its
            // credential avoids duplicate pending rows after refresh/retry while
            // still allowing another browser with the same display name to exist.
            if (is_string($retryCredential) && $retryCredential !== '') {
                $existing = GuestMeetingSession::query()
                    ->with(['invitation.meeting'])
                    ->where('invitation_id', $locked->id)
                    ->where('credential_hash', $this->hash($retryCredential))
                    ->lockForUpdate()
                    ->first();

                if ($existing && $this->isReusablePendingRetry($existing, $locked)) {
                    return new IssuedGuestMeetingSession($existing, $retryCredential);
                }

                // A form re-submit from an admitted guest is not a new pending
                // request. Preserve that guest's independent authority and
                // avoid creating a second GuestMeetingSession/participant.
                if ($existing && $this->isAdmittedRetryContinuation($existing, $locked)) {
                    return new IssuedGuestMeetingSession($existing, $retryCredential);
                }
            }

            $credential = bin2hex(random_bytes(32));
            $expiresAt = now()->addMinutes((int) config('guest-meetings.session_ttl_minutes'));
            if ($locked->expires_at->lt($expiresAt)) {
                $expiresAt = $locked->expires_at;
            }
            $session = GuestMeetingSession::query()->create([
                'meeting_id' => $locked->meeting_id,
                'invitation_id' => $locked->id,
                'display_name_snapshot' => $displayName,
                'credential_hash' => $this->hash($credential),
                'status' => GuestMeetingSessionStatus::Pending,
                'expires_at' => $expiresAt,
                'requested_at' => now(),
            ]);

            return new IssuedGuestMeetingSession($session, $credential);
        });
    }

    public function authenticate(string $publicUuid, ?string $credential): ?GuestMeetingSession
    {
        if (! is_string($credential) || $credential === '') {
            return null;
        }

        $session = GuestMeetingSession::query()
            ->with(['invitation.meeting'])
            ->where('public_uuid', $publicUuid)
            ->first();

        if (! $session
            || ! hash_equals($session->credential_hash, $this->hash($credential))
            || ! $this->isUsable($session)) {
            return null;
        }

        return $session;
    }

    /**
     * Proves ownership without granting meeting access. It is only used to show
     * the guest's own terminal waiting-room state (for example, rejection).
     */
    public function authenticateOwner(string $publicUuid, ?string $credential): ?GuestMeetingSession
    {
        if (! is_string($credential) || $credential === '') {
            return null;
        }

        $session = GuestMeetingSession::query()
            ->with(['invitation.meeting'])
            ->where('public_uuid', $publicUuid)
            ->first();

        if (! $session
            || ! hash_equals($session->credential_hash, $this->hash($credential))
            || ! $this->hasValidRelationship($session)) {
            return null;
        }

        return $session;
    }

    public function isUsable(GuestMeetingSession $session): bool
    {
        if (! in_array($session->status, [GuestMeetingSessionStatus::Pending, GuestMeetingSessionStatus::Admitted], true)
            || $session->invalidated_at !== null
            || ! $session->expires_at?->isFuture()
            || ! $this->hasValidRelationship($session)) {
            return false;
        }

        // Invitation revocation blocks new requests. It must not retroactively
        // evict an already admitted guest; Guest-D will decide room-token policy.
        return $session->status === GuestMeetingSessionStatus::Admitted
            ? in_array($session->meeting?->status, [MeetingStatus::Scheduled, MeetingStatus::Starting, MeetingStatus::Active], true)
            : $this->invitations->isAvailable($session->invitation);
    }

    /**
     * Room entry is stricter than a waiting-room state: an admitted guest may
     * participate only while its own meeting is actively running.
     */
    public function canJoinActiveMeeting(GuestMeetingSession $session): bool
    {
        $session->loadMissing('invitation.meeting');

        return $session->status === GuestMeetingSessionStatus::Admitted
            && $session->meeting?->status === MeetingStatus::Active
            && $this->isUsable($session);
    }

    public function state(GuestMeetingSession $session): string
    {
        $session->loadMissing('invitation.meeting');
        if (in_array($session->meeting?->status, [MeetingStatus::Ending, MeetingStatus::Ended, MeetingStatus::Cancelled], true)) {
            return 'meeting_ended';
        }
        if (! $this->hasValidRelationship($session) || $session->invalidated_at !== null) {
            return $session->status === GuestMeetingSessionStatus::Removed ? 'removed' : 'expired';
        }
        if (! $session->expires_at?->isFuture()) {
            return 'expired';
        }
        if ($session->status === GuestMeetingSessionStatus::Pending && ! $this->invitations->isAvailable($session->invitation)) {
            return 'unavailable';
        }

        return $session->status->value;
    }

    public function cancel(GuestMeetingSession $session): GuestMeetingSession
    {
        return DB::transaction(function () use ($session) {
            $locked = GuestMeetingSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== GuestMeetingSessionStatus::Pending || $locked->invalidated_at !== null) {
                throw ValidationException::withMessages(['session' => 'This guest request can no longer be cancelled.']);
            }

            $locked->update([
                'status' => GuestMeetingSessionStatus::Removed,
                'invalidated_at' => now(),
                'decided_at' => now(),
            ]);

            return $locked;
        });
    }

    public function decide(User $actor, GuestMeetingSession $session, GuestMeetingSessionStatus $decision): GuestMeetingSession
    {
        abort_unless(in_array($decision, [GuestMeetingSessionStatus::Admitted, GuestMeetingSessionStatus::Rejected], true), 422);

        return DB::transaction(function () use ($actor, $session, $decision) {
            $locked = GuestMeetingSession::query()->with(['meeting', 'invitation'])->lockForUpdate()->findOrFail($session->id);
            Gate::forUser($actor)->authorize('manageGuestInvitations', $locked->meeting);

            if ($locked->status !== GuestMeetingSessionStatus::Pending
                || $locked->invalidated_at !== null
                || ! $locked->expires_at?->isFuture()
                || ! $this->hasValidRelationship($locked)
                || ! $this->invitations->isAvailable($locked->invitation)) {
                throw ValidationException::withMessages(['session' => 'This guest request is no longer available for a decision.']);
            }

            $locked->update([
                'status' => $decision,
                'decided_at' => now(),
                'decided_by' => $actor->id,
            ]);

            return $locked;
        });
    }

    /**
     * Server-side guest leave/revocation. Repeated terminal requests are no-ops.
     * A caller may use should_disconnect only after this transaction commits.
     *
     * @return array{session: GuestMeetingSession, should_disconnect: bool}
     */
    public function leave(GuestMeetingSession $session): array
    {
        return DB::transaction(function () use ($session) {
            $candidate = GuestMeetingSession::query()->findOrFail($session->id);
            // Every Guest-E lifecycle mutation takes Meeting -> Session ->
            // Participant locks. This keeps leave/remove/end/expiry races from
            // inverting rows while preserving the Laravel revocation boundary.
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($candidate->meeting_id);
            $locked = GuestMeetingSession::query()
                ->with('invitation')
                ->lockForUpdate()
                ->findOrFail($session->id);
            $locked->setRelation('meeting', $meeting);

            if ($locked->invalidated_at !== null || ! in_array($locked->status, [GuestMeetingSessionStatus::Pending, GuestMeetingSessionStatus::Admitted], true)) {
                return ['session' => $locked, 'should_disconnect' => false];
            }

            $expired = ! $locked->expires_at?->isFuture()
                || in_array($locked->meeting?->status, [MeetingStatus::Ending, MeetingStatus::Ended, MeetingStatus::Cancelled], true);
            $locked->update([
                'status' => $expired ? GuestMeetingSessionStatus::Expired : GuestMeetingSessionStatus::Removed,
                'invalidated_at' => now(),
                'decided_at' => now(),
            ]);

            $participant = $locked->meeting_participant_id
                ? MeetingParticipant::query()->lockForUpdate()->find($locked->meeting_participant_id)
                : null;
            if ($participant && $participant->meeting_id === $locked->meeting_id) {
                $participant->update(['join_reserved_until' => null]);
                $this->removals->request($locked, $participant);
            }

            return ['session' => $locked->fresh(['meeting', 'participant']), 'should_disconnect' => $participant !== null];
        });
    }

    /**
     * Turns a naturally expired or terminal-meeting session into durable
     * revocation when its owner next checks status.
     *
     * @return array{session: GuestMeetingSession, should_disconnect: bool}
     */
    public function enforceTerminalState(GuestMeetingSession $session): array
    {
        $session->loadMissing('meeting');
        if ($session->invalidated_at !== null
            || ($session->expires_at?->isFuture()
                && ! in_array($session->meeting?->status, [MeetingStatus::Ending, MeetingStatus::Ended, MeetingStatus::Cancelled], true))) {
            return ['session' => $session, 'should_disconnect' => false];
        }

        return $this->leave($session);
    }

    /** Revokes only the guest session that owns a host-removed participant row. */
    public function lockForParticipant(int $participantId): ?GuestMeetingSession
    {
        return GuestMeetingSession::query()
            ->where('meeting_participant_id', $participantId)
            ->lockForUpdate()
            ->first();
    }

    /** The caller holds Meeting -> Session -> Participant locks. */
    public function invalidateForParticipant(MeetingParticipant $participant, ?GuestMeetingSession $session = null): ?GuestMeetingSession
    {
        $session ??= GuestMeetingSession::query()
            ->where('meeting_participant_id', $participant->id)
            ->lockForUpdate()
            ->first();
        if (! $session || $session->invalidated_at !== null) {
            return $session;
        }

        $session->update([
            'status' => GuestMeetingSessionStatus::Removed,
            'invalidated_at' => now(),
            'decided_at' => now(),
        ]);
        $session->setRelation('meeting', $participant->meeting);
        $this->removals->request($session, $participant);

        return $session;
    }

    /** Invalidates guest authority and capacity reservations before room deletion. */
    public function invalidateForMeeting(int $meetingId): int
    {
        $sessions = GuestMeetingSession::query()
            ->where('meeting_id', $meetingId)
            ->whereIn('status', [GuestMeetingSessionStatus::Pending->value, GuestMeetingSessionStatus::Admitted->value])
            ->with('participant')
            ->whereNull('invalidated_at')
            ->lockForUpdate()
            ->get();
        if ($sessions->isEmpty()) {
            return 0;
        }

        $meeting = Meeting::query()->findOrFail($meetingId);
        foreach ($sessions as $session) {
            $session->setRelation('meeting', $meeting);
            $session->update(['status' => GuestMeetingSessionStatus::Expired, 'invalidated_at' => now(), 'decided_at' => now()]);
            $participant = $session->participant;
            if ($participant && $participant->meeting_id === $meetingId) {
                $participant->update(['join_reserved_until' => null]);
                $this->removals->request($session, $participant);
            }
        }

        return $sessions->count();
    }

    /** @return int number of sessions durably expired without browser polling */
    public function expireDue(int $limit): int
    {
        $ids = GuestMeetingSession::query()
            ->whereIn('status', [GuestMeetingSessionStatus::Pending->value, GuestMeetingSessionStatus::Admitted->value])
            ->whereNull('invalidated_at')->where('expires_at', '<=', now())
            ->orderBy('id')->limit($limit)->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            $changed = DB::transaction(function () use ($id) {
                $candidate = GuestMeetingSession::query()->find($id);
                if (! $candidate) return false;
                $meeting = Meeting::query()->lockForUpdate()->find($candidate->meeting_id);
                $locked = GuestMeetingSession::query()->lockForUpdate()->find($id);
                if (! $meeting || ! $locked || $locked->invalidated_at || ! $locked->expires_at?->isPast()) return false;
                $locked->setRelation('meeting', $meeting);
                $participant = $locked->meeting_participant_id ? MeetingParticipant::query()->lockForUpdate()->find($locked->meeting_participant_id) : null;
                $locked->update(['status' => GuestMeetingSessionStatus::Expired, 'invalidated_at' => now(), 'decided_at' => now()]);
                if ($participant && $participant->meeting_id === $locked->meeting_id) {
                    $participant->update(['join_reserved_until' => null]);
                    $this->removals->request($locked, $participant);
                }
                return true;
            });
            $expired += (int) $changed;
        }

        return $expired;
    }

    private function hash(string $credential): string
    {
        return hash('sha256', $credential);
    }

    private function hasValidRelationship(GuestMeetingSession $session): bool
    {
        return $session->invitation !== null
            && $session->meeting !== null
            && $session->meeting_id === $session->invitation->meeting_id;
    }

    /** The retry cookie is idempotent only for its still-usable pending request. */
    private function isReusablePendingRetry(GuestMeetingSession $session, GuestMeetingInvitation $invitation): bool
    {
        return $session->invitation_id === $invitation->id
            && $session->meeting_id === $invitation->meeting_id
            && $session->status === GuestMeetingSessionStatus::Pending
            && $this->isUsable($session);
    }

    /**
     * An admitted Guest does not become a fresh pending request on a duplicate
     * submit. This is a continuation only; terminal sessions never enter here.
     */
    private function isAdmittedRetryContinuation(GuestMeetingSession $session, GuestMeetingInvitation $invitation): bool
    {
        return $session->invitation_id === $invitation->id
            && $session->meeting_id === $invitation->meeting_id
            && $session->status === GuestMeetingSessionStatus::Admitted
            && $this->isUsable($session);
    }
}
