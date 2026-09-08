<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
        if ($schoolClass->academicYear?->starts_on === null || $schoolClass->academicYear?->ends_on === null) {
            $schoolClass->load('academicYear');
        }

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

    public function canViewRegister(User $user, SchoolClass $schoolClass, CarbonInterface|string|null $attendanceDate = null): bool
    {
        return $this->isAdministrator($user)
            || ($attendanceDate ? $this->isTeacherAssignedForDate($user, $schoolClass, $attendanceDate) : $this->isCurrentClassTeacher($user, $schoolClass));
    }

    public function canViewClassHistory(User $user, SchoolClass $schoolClass): bool
    {
        if ($this->isAdministrator($user)) {
            return true;
        }

        if (! $this->isEligible($user)) {
            return false;
        }

        if ($this->isCurrentClassTeacher($user, $schoolClass)) {
            return true;
        }

        if ($schoolClass->academicYear?->starts_on === null || $schoolClass->academicYear?->ends_on === null) {
            $schoolClass->load('academicYear');
        }

        return $schoolClass->teacherAssignments()
            ->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))
            ->whereDate('starts_on', '<=', $schoolClass->academicYear->ends_on)
            ->where(fn (Builder $assignments) => $assignments->whereNull('ends_on')->orWhereDate('ends_on', '>=', $schoolClass->academicYear->starts_on))
            ->exists();
    }

    /** @return Collection<int, int> */
    public function viewableClassIdsForHistory(User $user, ?int $academicYearId = null): Collection
    {
        if ($this->isAdministrator($user)) {
            return SchoolClass::query()
                ->when($academicYearId, fn (Builder $classes, int $yearId) => $classes->where('academic_year_id', $yearId))
                ->pluck('id');
        }

        if (! $this->isEligible($user)) {
            return collect();
        }

        return TeacherClassAssignment::query()
            ->with('schoolClass.academicYear')
            ->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))
            ->get()
            ->filter(function (TeacherClassAssignment $assignment) use ($academicYearId) {
                $schoolClass = $assignment->schoolClass;
                if (! $schoolClass || ($academicYearId && $schoolClass->academic_year_id !== $academicYearId)) {
                    return false;
                }

                return $assignment->starts_on->lte($schoolClass->academicYear->ends_on)
                    && ($assignment->ends_on === null || $assignment->ends_on->gte($schoolClass->academicYear->starts_on));
            })
            ->pluck('school_class_id')
            ->unique()
            ->values();
    }

    public function canMutateRegister(User $user, SchoolClass $schoolClass): bool
    {
        return $this->isAdministrator($user) ? $this->isAcademicallyActive($schoolClass) : $this->isCurrentClassTeacher($user, $schoolClass);
    }

    private function isTeacherAssignedForDate(User $user, SchoolClass $schoolClass, CarbonInterface|string $attendanceDate): bool
    {
        return $this->isEligible($user)
            && $schoolClass->teacherAssignments()
                ->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))
                ->whereDate('starts_on', '<=', $attendanceDate)
                ->where(fn (Builder $assignments) => $assignments->whereNull('ends_on')->orWhereDate('ends_on', '>=', $attendanceDate))
                ->exists();
    }

    public function ownsAttendanceRecord(User $user, AttendanceRecord $record): bool
    {
        $record->loadMissing('studentProfile');

        return $this->isEligible($user)
            && $user->hasRole('Student')
            && $record->studentProfile->user_id === $user->id;
    }
}
