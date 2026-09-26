<?php

namespace App\Services;

use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Historical authorization for per-meeting attendance reports.
 *
 * This deliberately does not reuse MeetingAccess::canAccessMeeting(), which
 * resolves CURRENT class/subject relationships. A teacher who was assigned on
 * the day a meeting occurred must still be able to read that meeting's report
 * after the assignment ended, and a student must never receive a whole-class
 * report. Live meeting authorization is left untouched.
 */
class MeetingAttendanceAccess
{
    public function isEligible(User $user): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail();
    }

    public function isAdministrator(User $user): bool
    {
        return $this->isEligible($user) && $user->hasAnyRole(['Admin', 'Super Admin']);
    }

    /**
     * The calendar date a meeting occurred on, in the configured academic
     * timezone. All historical lookups are anchored to this date.
     */
    public function occurrenceDate(Meeting $meeting): string
    {
        return $meeting->scheduled_start_at
            ->timezone(config('calendar.default_timezone'))
            ->toDateString();
    }

    public function canViewReport(User $user, Meeting $meeting): bool
    {
        if (! $this->isEligible($user)) {
            return false;
        }

        // Coarse capability gate. The historical assignment checks below stay
        // the deciding factor; this only requires the existing meetings
        // participants permission to be present on the account.
        if (! $user->can('meetings.participants.view')) {
            return false;
        }

        if ($this->isAdministrator($user)) {
            return true;
        }

        if (! $user->hasRole('Teacher')) {
            return false;
        }

        return $this->wasAssignedOn($user, $meeting);
    }

    /**
     * True when the teacher held a class or class-subject assignment whose
     * effective window covers the meeting occurrence date. `current_slot` is
     * intentionally not used, so historical meetings stay readable.
     */
    public function wasAssignedOn(User $user, Meeting $meeting): bool
    {
        $date = $this->occurrenceDate($meeting);

        if ($this->wasAssignedToClassOn($user, $meeting->schoolClass, $date)) {
            return true;
        }

        $classSubject = $meeting->classSubject;
        if (! $classSubject || $classSubject->school_class_id !== $meeting->school_class_id) {
            return false;
        }

        return $this->wasAssignedToSubjectOn($user, $classSubject, $date);
    }

    public function wasAssignedToClassOn(User $user, SchoolClass $schoolClass, string $date): bool
    {
        return $schoolClass->teacherAssignments()
            ->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))
            ->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $assignments) => $assignments
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $date))
            ->exists();
    }

    public function wasAssignedToSubjectOn(User $user, ClassSubject $classSubject, string $date): bool
    {
        return $classSubject->teacherAssignments()
            ->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))
            ->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $assignments) => $assignments
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $date))
            ->exists();
    }
}
