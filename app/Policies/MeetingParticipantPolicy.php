<?php

namespace App\Policies;

use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\MeetingAccess;

class MeetingParticipantPolicy
{
    public function __construct(private MeetingAccess $access) {}

    public function view(User $user, MeetingParticipant $participant): bool
    {
        return $user->can('meetings.participants.view')
            && $this->access->canAccessMeeting($user, $participant->meeting);
    }

    public function remove(User $user, MeetingParticipant $participant): bool
    {
        return app(MeetingPolicy::class)->removeParticipant($user, $participant->meeting, $participant);
    }
}
