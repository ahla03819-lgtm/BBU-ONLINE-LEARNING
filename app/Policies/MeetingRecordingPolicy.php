<?php

namespace App\Policies;

use App\Enums\MeetingRecordingStatus;
use App\Models\MeetingRecording;
use App\Models\User;
use App\Services\MeetingAccess;

class MeetingRecordingPolicy
{
    public function __construct(private MeetingAccess $access) {}

    /**
     * Viewing a recording means being entitled to the meeting's own class
     * context. That is deliberately the same bar as reading the meeting, so a
     * student who could not read the class channel cannot watch a recording just
     * because they learned its public reference.
     */
    public function view(User $user, MeetingRecording $recording): bool
    {
        $recording->loadMissing('meeting');

        return $recording->meeting !== null
            && $this->access->canAccessMeeting($user, $recording->meeting);
    }

    /**
     * Only a watchable recording may be played, and playback still goes through
     * this check, so a Processing or Failed recording cannot be streamed by anyone.
     */
    public function play(User $user, MeetingRecording $recording): bool
    {
        if ($recording->trashed()) {
            return false;
        }

        return $recording->status === MeetingRecordingStatus::Ready
            && $recording->isWatchable()
            && $this->view($user, $recording);
    }

    public function delete(User $user, MeetingRecording $recording): bool
    {
        $recording->loadMissing('meeting');

        if ($recording->trashed()) {
            return false;
        }

        return $user->can('recordings.delete')
            && $recording->meeting !== null
            && $this->access->canAccessMeeting($user, $recording->meeting);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('recordings.delete') && $this->access->isEligibleAccount($user);
    }

    public function restore(User $user, MeetingRecording $recording): bool
    {
        $recording->loadMissing('meeting');

        return $recording->trashed()
            && $user->can('recordings.restore')
            && $recording->meeting !== null
            && $this->access->canAccessMeeting($user, $recording->meeting);
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('recordings.restore') && $this->canManageTrash($user);
    }

    public function purge(User $user, MeetingRecording $recording): bool
    {
        $recording->loadMissing('meeting');

        // Must be in trash first for manual purge
        if (! $recording->trashed()) {
            return false;
        }

        // Only administrators with explicit purge capability
        return $this->purgeAny($user)
            && $recording->meeting !== null
            && $this->access->canAccessMeeting($user, $recording->meeting);
    }

    public function purgeAny(User $user): bool
    {
        return $user->can('recordings.purge') && $this->access->isAdministrator($user);
    }

    public function viewTrash(User $user): bool
    {
        return $this->canManageTrash($user)
            && ($user->can('recordings.restore') || $this->purgeAny($user));
    }

    private function canManageTrash(User $user): bool
    {
        return $this->access->isEligibleAccount($user)
            && ($this->access->isAdministrator($user) || $user->hasRole('Teacher'));
    }
}
