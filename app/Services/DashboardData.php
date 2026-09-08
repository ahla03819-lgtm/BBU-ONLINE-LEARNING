<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\AttendanceStatus;
use App\Enums\MeetingStatus;
use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AttendanceRegister;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DashboardData
{
    public function for(User $user): array
    {
        return match ($user->effectiveRole()) {
            'Super Admin' => $this->admin($user, 'super-admin'),
            'Admin' => $this->admin($user),
            'Teacher' => $this->teacher($user),
            default => $this->student($user),
        };
    }

    private function admin(User $user, string $variant = 'admin'): array
    {
        $classes = $user->can('classes.view')
            ? SchoolClass::query()->with('academicYear:id,name')->orderBy('name')->get()
            : collect();

        $metrics = array_filter([
            $user->can('students.view') ? ['label' => 'Students', 'value' => StudentProfile::query()->count(), 'hint' => 'Student profiles'] : null,
            $user->can('teachers.view') ? ['label' => 'Teachers', 'value' => TeacherProfile::query()->count(), 'hint' => 'Teacher profiles'] : null,
            $user->can('classes.view') ? ['label' => 'Classes', 'value' => $classes->count(), 'hint' => 'Configured classes'] : null,
            $user->can('subjects.view') ? ['label' => 'Subjects', 'value' => Subject::query()->where('is_active', true)->count(), 'hint' => 'Active subjects'] : null,
        ]);

        return $this->base($variant, $user, $classes, $metrics);
    }

    private function teacher(User $user): array
    {
        $classes = SchoolClass::query()->with(['academicYear:id,name', 'gradeLevel:id,name'])
            ->whereHas('classSubjects.teacherAssignments', fn (Builder $assignments) => $assignments->where('current_slot', 1)->whereHas('teacherProfile', fn (Builder $profiles) => $profiles->where('user_id', $user->id)))
            ->orderBy('name')->get();

        return $this->base('teacher', $user, $classes, [
            ['label' => 'My classes', 'value' => $classes->count(), 'hint' => 'Current subject assignments'],
            ['label' => 'Students', 'value' => $classes->sum(fn (SchoolClass $class) => $class->enrollments()->where('current_slot', 1)->count()), 'hint' => 'Current class rosters'],
            ['label' => 'Assignments', 'value' => $this->assignmentsForClasses($classes)->count(), 'hint' => 'Accessible coursework'],
            ['label' => 'Meetings', 'value' => $this->meetingsForClasses($user, $classes)->count(), 'hint' => 'Scheduled or active'],
        ]);
    }

    private function student(User $user): array
    {
        $classes = SchoolClass::query()->with(['academicYear:id,name', 'gradeLevel:id,name'])
            ->whereHas('enrollments', fn (Builder $enrollments) => $enrollments->where('current_slot', 1)->whereHas('studentProfile', fn (Builder $students) => $students->where('user_id', $user->id)))
            ->orderBy('name')->get();

        return $this->base('student', $user, $classes, [
            ['label' => 'My classes', 'value' => $classes->count(), 'hint' => 'Current class memberships'],
            ['label' => 'Attendance records', 'value' => $user->studentProfile?->attendanceRecords()->count() ?? 0, 'hint' => 'Personal history'],
            ['label' => 'Coursework', 'value' => $this->assignmentsForClasses($classes)->where('status', 'published')->count(), 'hint' => 'Available assignments'],
            ['label' => 'Unread activity', 'value' => $user->userNotifications()->whereNull('read_at')->count(), 'hint' => 'Notifications'],
        ]);
    }

    private function base(string $variant, User $user, $classes, array $metrics): array
    {
        $classIds = $classes->pluck('id');
        $today = $user->can('attendance.view') ? AttendanceRegister::query()->whereIn('school_class_id', $classIds)->whereDate('attendance_date', today())->withCount([
            'records as present_count' => fn (Builder $query) => $query->where('status', AttendanceStatus::Present->value),
            'records as absent_count' => fn (Builder $query) => $query->where('status', AttendanceStatus::Absent->value),
            'records as late_count' => fn (Builder $query) => $query->where('status', AttendanceStatus::Late->value),
            'records as excused_count' => fn (Builder $query) => $query->where('status', AttendanceStatus::Excused->value),
        ])->get() : collect();
        $assignments = $user->can('assignments.view') ? $this->assignmentsForClasses($classes)->with('classSubject.subject:id,name')->latest()->limit(5)->get() : collect();
        $meetings = $user->can('meetings.view') ? $this->meetingsForClasses($user, $classes)->with('schoolClass:id,name,section')->orderBy('scheduled_start_at')->limit(5)->get() : collect();
        $activeYear = AcademicYear::query()->where('status', AcademicYearStatus::Active)->first(['id', 'name']);

        return [
            'variant' => $variant,
            'academicYear' => $activeYear?->only('id', 'name'),
            'metrics' => $metrics,
            'classes' => $classes->take(6)->map(fn (SchoolClass $class) => ['id' => $class->id, 'name' => trim($class->name.' '.$class->section), 'academic_year' => $class->academicYear?->name, 'coursework_url' => $user->can('assignments.view') ? route('coursework.index', $class) : null, 'attendance_url' => $user->can('attendance.view') ? route('attendance.index', ['school_class_id' => $class->id]) : null])->values(),
            'attendanceToday' => ['present' => $today->sum('present_count'), 'absent' => $today->sum('absent_count'), 'late' => $today->sum('late_count'), 'excused' => $today->sum('excused_count'), 'total' => $today->sum(fn ($register) => $register->present_count + $register->absent_count + $register->late_count + $register->excused_count)],
            'assignments' => $assignments->map(fn (Assignment $assignment) => ['title' => $assignment->title, 'subject' => $assignment->classSubject->subject->name, 'status' => $assignment->status->value, 'due_at' => $assignment->due_at?->toIso8601String()])->values(),
            'meetings' => $meetings->map(fn (Meeting $meeting) => ['title' => $meeting->title, 'school_class' => trim($meeting->schoolClass->name.' '.$meeting->schoolClass->section), 'status' => $meeting->status->value, 'scheduled_start_at' => $meeting->scheduled_start_at?->toIso8601String()])->values(),
            'reportingPeriods' => $user->can('results.view') ? ($activeYear?->reportingPeriods()->orderBy('sequence')->get(['id', 'name', 'status'])->map->only('id', 'name', 'status')->values() ?? []) : [],
            'quickActions' => $this->actions($user, $variant),
        ];
    }

    private function assignmentsForClasses($classes): Builder
    {
        return Assignment::query()->whereHas('classSubject', fn (Builder $subjects) => $subjects->whereIn('school_class_id', $classes->pluck('id')));
    }

    private function meetingsForClasses(User $user, $classes): Builder
    {
        return app(MeetingAccess::class)->meetingsFor($user)->whereIn('school_class_id', $classes->pluck('id'))->whereIn('status', [MeetingStatus::Scheduled->value, MeetingStatus::Starting->value, MeetingStatus::Active->value]);
    }

    private function actions(User $user, string $variant): array
    {
        $actions = [
            ['label' => 'Students', 'url' => '/people', 'visible' => $user->can('students.view')],
            ['label' => 'Attendance', 'url' => $variant === 'student' ? '/my-attendance' : '/attendance', 'visible' => $user->can($variant === 'student' ? 'attendance.view-own' : 'attendance.view')],
            ['label' => 'Results', 'url' => $variant === 'student' ? '/my-results' : '/results', 'visible' => $user->can($variant === 'student' ? 'results.view-own' : 'results.view')],
            ['label' => 'Notifications', 'url' => '/notifications', 'visible' => $user->can('notifications.view')],
        ];

        return array_values(array_filter($actions, fn (array $action) => $action['visible']));
    }
}
