<?php

namespace App\Policies;

use App\Enums\AttendanceRegisterStatus;
use App\Models\AttendanceRegister;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttendanceAccess;

class AttendanceRegisterPolicy
{
    public function __construct(private AttendanceAccess $access) {}

    public function view(User $user, AttendanceRegister $register): bool
    {
        return $user->can('attendance.view') && $this->access->canViewRegister($user, $register->schoolClass, $register->attendance_date);
    }

    public function record(User $user, SchoolClass|AttendanceRegister $subject): bool
    {
        $register = $subject instanceof AttendanceRegister ? $subject : null;
        $schoolClass = $register?->schoolClass ?? $subject;

        return $user->can('attendance.record')
            && (! $register || $register->status === AttendanceRegisterStatus::Draft)
            && $this->access->canMutateRegister($user, $schoolClass);
    }

    public function finalize(User $user, AttendanceRegister $register): bool
    {
        return $user->can('attendance.finalize')
            && $register->status === AttendanceRegisterStatus::Draft
            && $this->access->canMutateRegister($user, $register->schoolClass);
    }

    public function correct(User $user, AttendanceRegister $register): bool
    {
        return $user->can('attendance.correct')
            && $register->status === AttendanceRegisterStatus::Finalized
            && $this->access->canMutateRegister($user, $register->schoolClass);
    }
}
