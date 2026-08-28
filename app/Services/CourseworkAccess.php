<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\AssignmentStatus;
use App\Enums\ClassSubjectStatus;
use App\Enums\SchoolClassStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CourseworkAccess
{
    public function canAccessClass(User $user, SchoolClass $class): bool
    {
        if (! $this->isEligible($user)) {
            return false;
        }
        if ($this->isAdministrator($user)) {
            return true;
        }
        if ($user->hasRole('Teacher')) {
            return $class->classSubjects()->whereHas('teacherAssignments.teacherProfile', fn (Builder $q) => $q->where('user_id', $user->id))->exists();
        }

        return $user->hasRole('Student') && $class->enrollments()->whereHas('studentProfile', fn (Builder $q) => $q->where('user_id', $user->id))->exists();
    }

    public function isAdministrator(User $user): bool
    {
        return $this->isEligible($user) && $user->hasAnyRole(['Super Admin', 'Admin']);
    }

    public function isCurrentSubjectTeacher(User $user, ClassSubject $subject): bool
    {
        return $this->isEligible($user) && $this->isAcademicallyActive($subject)
            && $subject->teacherAssignments()->where('current_slot', 1)
                ->whereHas('teacherProfile', fn (Builder $q) => $q->where('user_id', $user->id))->exists();
    }

    public function isCurrentStudent(User $user, ClassSubject $subject): bool
    {
        return $this->isEligible($user) && $this->isAcademicallyActive($subject)
            && $subject->schoolClass->enrollments()->where('current_slot', 1)
                ->whereHas('studentProfile', fn (Builder $q) => $q->where('user_id', $user->id))->exists();
    }

    public function canViewAssignment(User $user, Assignment $assignment): bool
    {
        if (! $this->isEligible($user)) {
            return false;
        }
        if ($this->isAdministrator($user)) {
            return true;
        }
        $assignment->loadMissing('classSubject.schoolClass');
        if ($user->hasRole('Teacher')) {
            return $this->isCurrentSubjectTeacher($user, $assignment->classSubject)
                || $assignment->created_by === $user->id
                || $this->historicalTeacherOverlap($user, $assignment);
        }
        if (! $user->hasRole('Student') || $assignment->status === AssignmentStatus::Draft || ! $assignment->published_at) {
            return false;
        }

        return $this->studentEnrollmentOverlap($user, $assignment);
    }

    public function canMutateAssignment(User $user, Assignment|ClassSubject $subject): bool
    {
        $classSubject = $subject instanceof Assignment ? $subject->classSubject : $subject;

        return $this->isAdministrator($user) ? $this->isAcademicallyActive($classSubject) : $this->isCurrentSubjectTeacher($user, $classSubject);
    }

    public function canSubmit(User $user, Assignment $assignment): bool
    {
        return $assignment->status === AssignmentStatus::Published && $this->isCurrentStudent($user, $assignment->classSubject);
    }

    public function canViewSubmission(User $user, AssignmentSubmission $submission): bool
    {
        $submission->loadMissing(['assignment.classSubject.schoolClass', 'studentProfile']);
        if ($this->isAdministrator($user) || $this->isCurrentSubjectTeacher($user, $submission->assignment->classSubject) || $this->historicalTeacherOverlap($user, $submission->assignment)) {
            return true;
        }

        return $submission->studentProfile->user_id === $user->id && $this->canViewAssignment($user, $submission->assignment);
    }

    public function canReview(User $user, AssignmentSubmission $submission): bool
    {
        return $this->canMutateAssignment($user, $submission->assignment)
            && in_array($submission->assignment->status, [AssignmentStatus::Published, AssignmentStatus::Closed], true);
    }

    private function isAcademicallyActive(ClassSubject $subject): bool
    {
        $subject->loadMissing('schoolClass.academicYear');

        return $subject->status === ClassSubjectStatus::Active
            && $subject->schoolClass->status === SchoolClassStatus::Active
            && $subject->schoolClass->academicYear->status === AcademicYearStatus::Active;
    }

    private function studentEnrollmentOverlap(User $user, Assignment $assignment): bool
    {
        $date = $assignment->published_at->toDateString();

        return $assignment->classSubject->schoolClass->enrollments()
            ->whereDate('enrolled_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ended_on')->orWhereDate('ended_on', '>=', $date))
            ->whereHas('studentProfile', fn (Builder $q) => $q->where('user_id', $user->id))->exists();
    }

    private function historicalTeacherOverlap(User $user, Assignment $assignment): bool
    {
        if (! $assignment->published_at) {
            return false;
        }
        $start = $assignment->published_at->toDateString();
        $end = ($assignment->closed_at ?? $assignment->archived_at ?? now())->toDateString();

        return $assignment->classSubject->teacherAssignments()
            ->whereDate('starts_on', '<=', $end)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))
            ->whereHas('teacherProfile', fn (Builder $q) => $q->where('user_id', $user->id))->exists();
    }

    private function isEligible(User $user): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail();
    }
}
