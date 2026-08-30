<?php

namespace App\Services;

use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ResultsAccess
{
    public function __construct(private CourseworkAccess $coursework) {}

    public function isAdministrator(User $user): bool
    {
        return $this->coursework->isAdministrator($user);
    }

    public function canViewStaffResults(User $user): bool
    {
        return $user->can('results.view') && ($this->isAdministrator($user) || $user->hasRole('Teacher'));
    }

    public function canViewClassSubject(User $user, SchoolClass $schoolClass, ClassSubject $classSubject): bool
    {
        return $classSubject->school_class_id === $schoolClass->id
            && $this->canViewStaffResults($user)
            && ($this->isAdministrator($user) || $this->coursework->isCurrentSubjectTeacher($user, $classSubject));
    }

    public function canViewOwnResults(User $user): bool
    {
        return $user->can('results.view-own') && $user->hasRole('Student') && $user->isActive() && $user->hasVerifiedEmail();
    }

    public function currentStudentClasses(User $user): Builder
    {
        return SchoolClass::query()->whereHas('enrollments', fn (Builder $enrollments) => $enrollments->where('current_slot', 1)->whereHas('studentProfile', fn (Builder $students) => $students->where('user_id', $user->id)));
    }
}
