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
        return $recording->status === MeetingRecordingStatus::Ready
            && $recording->isWatchable()
            && $this->view($user, $recording);
    }
}