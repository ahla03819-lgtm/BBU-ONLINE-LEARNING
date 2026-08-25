<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\ClassSubjectStatus;
use App\Enums\SchoolClassStatus;
use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MeetingAccess
{
    public function isEligibleAccount(User $user): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail();
    }

    public function isAdministrator(User $user): bool
    {
        return $user->hasAnyRole(['Admin', 'Super Admin']);
    }

    public function isSuperAdministrator(User $user): bool
    {
        return $user->hasRole('Super Admin');
    }

    public function isAcademicallyActive(SchoolClass $schoolClass): bool
    {
        $schoolClass->loadMissing('academicYear');

        return $schoolClass->status === SchoolClassStatus::Active
            && $schoolClass->academicYear->status === AcademicYearStatus::Active;
    }

    public function isActiveClassSubject(ClassSubject $classSubject, SchoolClass $schoolClass): bool
    {
        return $classSubject->school_class_id === $schoolClass->id
            && $classSubject->status === ClassSubjectStatus::Active
            && $this->isAcademicallyActive($schoolClass);
    }

    public function hasValidNestedScope(Meeting $meeting): bool
    {
        if (! $meeting->class_subject_id) {
            return true;
        }

        $meeting->loadMissing('classSubject');

        return $meeting->classSubject?->school_class_id === $meeting->school_class_id;
    }

    public function isCurrentClassTeacher(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isAcademicallyActive($schoolClass)
            && $schoolClass->teacherAssignments()
                ->where('current_slot', 1)
                ->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))
                ->exists();
    }

    public function isCurrentSubjectTeacher(User $user, ClassSubject $classSubject): bool
    {
        $classSubject->loadMissing('schoolClass');

        return $this->isActiveClassSubject($classSubject, $classSubject->schoolClass)
            && $classSubject->teacherAssignments()
                ->where('current_slot', 1)
                ->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))
                ->exists();
    }

    public function isCurrentStudent(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isAcademicallyActive($schoolClass)
            && $schoolClass->enrollments()
                ->where('academic_year_id', $schoolClass->academic_year_id)
                ->where('current_slot', 1)
                ->whereHas('studentProfile', fn (Builder $students) => $students->where('user_id', $user->id))
                ->exists();
    }

    public function canAccessMeeting(User $user, Meeting $meeting): bool
    {
        if (! $this->isEligibleAccount($user) || ! $this->hasValidNestedScope($meeting)) {
            return false;
        }

        if ($this->isAdministrator($user)) {
            return true;
        }

        $meeting->loadMissing(['schoolClass', 'classSubject']);
        if (! $this->isAcademicallyActive($meeting->schoolClass)) {
            return false;
        }

        if ($user->hasRole('Student')) {
            return $this->isCurrentStudent($user, $meeting->schoolClass);
        }

        if (! $user->hasRole('Teacher')) {
            return false;
        }

        if ($this->isCurrentClassTeacher($user, $meeting->schoolClass)) {
            return ! $meeting->classSubject || $this->isActiveClassSubject($meeting->classSubject, $meeting->schoolClass);
        }

        return $meeting->classSubject
            && $this->isCurrentSubjectTeacher($user, $meeting->classSubject);
    }

    public function canManageMeeting(User $user, Meeting $meeting): bool
    {
        if (! $this->canAccessMeeting($user, $meeting)) {
            return false;
        }

        $meeting->loadMissing(['schoolClass', 'classSubject']);
        if (! $this->isAcademicallyActive($meeting->schoolClass)
            || ($meeting->classSubject && ! $this->isActiveClassSubject($meeting->classSubject, $meeting->schoolClass))) {
            return false;
        }

        if ($this->isAdministrator($user)) {
            return true;
        }

        if (! $user->hasRole('Teacher')) {
            return false;
        }

        return $this->isCurrentClassTeacher($user, $meeting->schoolClass)
            || ($meeting->classSubject && $this->isCurrentSubjectTeacher($user, $meeting->classSubject));
    }

    public function canParticipateInMeeting(User $user, Meeting $meeting): bool
    {
        if (! $this->canAccessMeeting($user, $meeting)) {
            return false;
        }

        $meeting->loadMissing(['schoolClass', 'classSubject']);

        return $this->isAcademicallyActive($meeting->schoolClass)
            && (! $meeting->classSubject || $this->isActiveClassSubject($meeting->classSubject, $meeting->schoolClass));
    }

    public function canCreateMeeting(User $user, SchoolClass $schoolClass, ?ClassSubject $classSubject = null): bool
    {
        if (! $this->isEligibleAccount($user) || ! $this->isAcademicallyActive($schoolClass)) {
            return false;
        }

        if ($classSubject && ! $this->isActiveClassSubject($classSubject, $schoolClass)) {
            return false;
        }

        if ($this->isAdministrator($user)) {
            return true;
        }

        if (! $user->hasRole('Teacher')) {
            return false;
        }

        if ($this->isCurrentClassTeacher($user, $schoolClass)) {
            return true;
        }

        return $classSubject && $this->isCurrentSubjectTeacher($user, $classSubject);
    }

    public function isEligibleHost(User $user, Meeting $meeting): bool
    {
        if (! $this->isEligibleAccount($user) || ! $this->hasValidNestedScope($meeting)) {
            return false;
        }

        $meeting->loadMissing(['schoolClass', 'classSubject']);
        if (! $this->isAcademicallyActive($meeting->schoolClass)
            || ($meeting->classSubject && ! $this->isActiveClassSubject($meeting->classSubject, $meeting->schoolClass))) {
            return false;
        }

        if ($this->isSuperAdministrator($user)) {
            return true;
        }

        if ($this->isCurrentClassTeacher($user, $meeting->schoolClass)) {
            return true;
        }

        return $meeting->classSubject
            && $this->isCurrentSubjectTeacher($user, $meeting->classSubject);
    }

    public function isAssignedEligibleHost(User $user, Meeting $meeting): bool
    {
        return $this->isEligibleHost($user, $meeting)
            && ($this->isSuperAdministrator($user) || $meeting->host_user_id === $user->id);
    }
}
