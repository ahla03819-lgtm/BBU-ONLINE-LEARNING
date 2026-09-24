<?php

namespace App\Policies;

use App\Enums\MeetingSeriesStatus;
use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\MeetingSeries;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MeetingAccess;

class MeetingSeriesPolicy
{
    public function __construct(private MeetingAccess $access) {}

    public function view(User $user, MeetingSeries $series): bool
    {
        return $user->can('meetings.view')
            && $this->access->canAccessClass($user, $series->schoolClass);
    }

    public function create(User $user, SchoolClass $schoolClass, ?ClassSubject $subject = null): bool
    {
        return $user->can('meetings.create')
            && $this->access->canCreateMeeting($user, $schoolClass, $subject);
    }

    public function update(User $user, MeetingSeries $series): bool
    {
        return $user->can('meetings.update')
            && $series->status === MeetingSeriesStatus::Active
            && $this->canManage($user, $series);
    }

    public function cancel(User $user, MeetingSeries $series): bool
    {
        return $user->can('meetings.cancel')
            && $this->canManage($user, $series);
    }

    private function canManage(User $user, MeetingSeries $series): bool
    {
        $series->loadMissing(['schoolClass', 'classSubject']);
        $meeting = new Meeting([
            'school_class_id' => $series->school_class_id,
            'class_subject_id' => $series->class_subject_id,
            'host_user_id' => $series->host_user_id,
        ]);
        $meeting->setRelation('schoolClass', $series->schoolClass);
        $meeting->setRelation('classSubject', $series->classSubject);

        return $this->access->canManageMeeting($user, $meeting);
    }
}
