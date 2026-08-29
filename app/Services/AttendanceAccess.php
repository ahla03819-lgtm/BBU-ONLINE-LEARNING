<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AttendanceAccess
{
    public function isEligible(User $user): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail();
    }

    public function isAdministrator(User $user): bool
    {
        return $this->isEligible($user) && $user->hasAnyRole(['Super Admin', 'Admin']);
    }

    public function isAcademicallyActive(SchoolClass $schoolClass): bool
    {
        $schoolClass->loadMissing('academicYear');

        return $schoolClass->status === SchoolClassStatus::Active
            && $schoolClass->academicYear->status === AcademicYearStatus::Active;
    }

    public function isCurrentClassTeacher(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isEligible($user)
            && $this->isAcademicallyActive($schoolClass)
            && $schoolClass->teacherAssignments()
                ->where('current_slot', 1)
                ->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))
                ->exists();
    }

    public function canViewRegister(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isAdministrator($user) || $this->isCurrentClassTeacher($user, $schoolClass);
    }

    public function canMutateRegister(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isAdministrator($user) ? $this->isAcademicallyActive($schoolClass) : $this->isCurrentClassTeacher($user, $schoolClass);
    }

    public function ownsAttendanceRecord(User $user, AttendanceRecord $record): bool
    {
        $record->loadMissing('studentProfile');

        return $this->isEligible($user)
            && $user->hasRole('Student')
            && $record->studentProfile->user_id === $user->id;
    }
}
