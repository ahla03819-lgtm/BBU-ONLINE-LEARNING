<?php

namespace App\Policies;

use App\Enums\AttendanceRegisterStatus;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\AttendanceAccess;

class AttendanceRecordPolicy
{
    public function __construct(private AttendanceAccess $access) {}

    public function view(User $user, AttendanceRecord $record): bool
    {
        return ($user->can('attendance.view') && $this->access->canViewRegister($user, $record->attendanceRegister->schoolClass, $record->attendanceRegister->attendance_date))
            || ($user->can('attendance.view-own') && $this->access->ownsAttendanceRecord($user, $record));
    }

    public function record(User $user, AttendanceRecord $record): bool
    {
        return $user->can('attendance.record')
            && $record->attendanceRegister->status === AttendanceRegisterStatus::Draft
            && $this->access->canMutateRegister($user, $record->attendanceRegister->schoolClass);
    }

    public function correct(User $user, AttendanceRecord $record): bool
    {
        return $user->can('attendance.correct')
            && $this->access->canMutateRegister($user, $record->attendanceRegister->schoolClass);
    }
}
