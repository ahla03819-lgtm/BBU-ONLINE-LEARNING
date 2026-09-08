<?php

namespace App\Policies;

use App\Models\AttendanceRecordRevision;
use App\Models\User;
use App\Services\AttendanceAccess;

class AttendanceRecordRevisionPolicy
{
    public function __construct(private AttendanceAccess $access) {}

    public function view(User $user, AttendanceRecordRevision $revision): bool
    {
        return $user->can('attendance.view')
            && $this->access->canViewRegister($user, $revision->attendanceRecord->attendanceRegister->schoolClass, $revision->attendanceRecord->attendanceRegister->attendance_date);
    }
}
