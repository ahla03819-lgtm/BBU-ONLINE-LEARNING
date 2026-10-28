<?php

namespace App\Services\GuestMeetings;

use App\Enums\MeetingStatus;
use App\Models\GuestMeetingInvitation;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class GuestMeetingInvitationService
{
    public function create(User $actor, Meeting $meeting): IssuedGuestMeetingInvitation
    {
        Gate::forUser($actor)->authorize('manageGuestInvitations', $meeting);

        return $this->newInvitation($meeting, $actor->id, 1);
    }

    public function rotate(User $actor, GuestMeetingInvitation $invitation): IssuedGuestMeetingInvitation
    {
        return DB::transaction(function () use ($actor, $invitation) {
            $locked = GuestMeetingInvitation::query()->with('meeting')->lockForUpdate()->findOrFail($invitation->id);
            Gate::forUser($actor)->authorize('manageGuestInvitations', $locked->meeting);
            $locked->update(['revoked_at' => $locked->revoked_at ?? now(), 'rotated_at' => now()]);

            return $this->newInvitation($locked->meeting, $actor->id, $locked->version + 1);
        });
    }

    public function revoke(User $actor, GuestMeetingInvitation $invitation): GuestMeetingInvitation
    {
        return DB::transaction(function () use ($actor, $invitation) {
            $locked = GuestMeetingInvitation::query()->with('meeting')->lockForUpdate()->findOrFail($invitation->id);
            Gate::forUser($actor)->authorize('manageGuestInvitations', $locked->meeting);
            $locked->update(['revoked_at' => $locked->revoked_at ?? now()]);

            return $locked;
        });
    }

    /**
     * Resolves only currently usable invitation secrets. All invalid states share
     * the same not-found result so public callers cannot enumerate invitations.
     */
    public function resolve(string $token): GuestMeetingInvitation
    {
        $invitation = GuestMeetingInvitation::query()
            ->with('meeting')
            ->where('token_hash', $this->hash($token))
            ->first();

        if (! $invitation || ! hash_equals($invitation->token_hash, $this->hash($token)) || ! $this->isAvailable($invitation)) {
            throw (new ModelNotFoundException)->setModel(GuestMeetingInvitation::class);
        }

        return $invitation;
    }

    public function isAvailable(GuestMeetingInvitation $invitation): bool
    {
        $invitation->loadMissing('meeting');

        return $invitation->revoked_at === null
            && $invitation->expires_at?->isFuture()
            && $invitation->meeting !== null
            && in_array($invitation->meeting->status, [MeetingStatus::Scheduled, MeetingStatus::Starting, MeetingStatus::Active], true);
    }

    /** Future lifecycle callers can invalidate availability without deleting audit history. */
    public function invalidateForMeeting(Meeting $meeting): int
    {
        return GuestMeetingInvitation::query()
            ->where('meeting_id', $meeting->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function newInvitation(Meeting $meeting, int $creatorId, int $version): IssuedGuestMeetingInvitation
    {
        abort_unless(in_array($meeting->status, [MeetingStatus::Scheduled, MeetingStatus::Starting, MeetingStatus::Active], true), 422);

        $token = bin2hex(random_bytes(32));
        $invitation = GuestMeetingInvitation::query()->create([
            'meeting_id' => $meeting->id,
            'created_by' => $creatorId,
            'token_hash' => $this->hash($token),
            'version' => $version,
            'expires_at' => now()->addMinutes((int) config('guest-meetings.invitation_ttl_minutes')),
        ]);

        return new IssuedGuestMeetingInvitation($invitation, $token);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
