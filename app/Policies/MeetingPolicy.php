<?php

namespace App\Policies;

use App\Enums\MeetingStatus;
use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MeetingAccess;

class MeetingPolicy
{
    public function __construct(private MeetingAccess $access) {}

    public function view(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.view') && $this->access->canAccessMeeting($user, $meeting);
    }

    public function create(User $user, SchoolClass $schoolClass, ?ClassSubject $classSubject = null): bool
    {
        return $user->can('meetings.create') && $this->access->canCreateMeeting($user, $schoolClass, $classSubject);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.update')
            && $meeting->status === MeetingStatus::Scheduled
            && $this->access->canManageMeeting($user, $meeting);
    }

    public function cancel(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.cancel')
            && in_array($meeting->status, [MeetingStatus::Scheduled, MeetingStatus::Cancelled], true)
            && $this->access->canManageMeeting($user, $meeting);
    }

    public function start(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.start')
            && in_array($meeting->status, [MeetingStatus::Scheduled, MeetingStatus::Starting, MeetingStatus::Active], true)
            && $this->access->canManageMeeting($user, $meeting)
            && $this->access->isAssignedEligibleHost($user, $meeting);
    }

    public function end(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.end')
            && in_array($meeting->status, [MeetingStatus::Active, MeetingStatus::Ending, MeetingStatus::Ended], true)
            && $this->access->canManageMeeting($user, $meeting)
            && $this->access->isAssignedEligibleHost($user, $meeting);
    }

    public function join(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.join')
            && $meeting->status === MeetingStatus::Active
            && $this->access->canParticipateInMeeting($user, $meeting)
            && ! $this->isRemoved($user, $meeting);
    }

    public function viewParticipants(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.participants.view')
            && $this->access->canAccessMeeting($user, $meeting);
    }

    public function removeParticipant(User $user, Meeting $meeting, ?MeetingParticipant $participant = null): bool
    {
        if (! $user->can('meetings.participants.remove')
            || $meeting->status !== MeetingStatus::Active
            || ! $this->access->canManageMeeting($user, $meeting)
            || (! $this->access->isAdministrator($user) && ! $this->access->isAssignedEligibleHost($user, $meeting))) {
            return false;
        }

        return ! $participant
            || ($participant->meeting_id === $meeting->id && ! $participant->removed_at && $participant->user_id !== $user->id);
    }

    public function issueToken(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.tokens.issue')
            && $meeting->status === MeetingStatus::Active
            && $this->access->canParticipateInMeeting($user, $meeting)
            && ! $this->isRemoved($user, $meeting);
    }

    public function reconcile(User $user, Meeting $meeting): bool
    {
        if (! in_array($meeting->status, [MeetingStatus::Starting, MeetingStatus::Ending], true)
            || ! $this->access->canManageMeeting($user, $meeting)) {
            return false;
        }

        if ($this->access->isAdministrator($user)) {
            return $user->can($meeting->status === MeetingStatus::Starting ? 'meetings.start' : 'meetings.end');
        }

        return $this->access->isAssignedEligibleHost($user, $meeting)
            && $user->can($meeting->status === MeetingStatus::Starting ? 'meetings.start' : 'meetings.end');
    }

    private function isRemoved(User $user, Meeting $meeting): bool
    {
        return $meeting->participants()
            ->where('user_id', $user->id)
            ->whereNotNull('removed_at')
            ->exists();
    }
}
