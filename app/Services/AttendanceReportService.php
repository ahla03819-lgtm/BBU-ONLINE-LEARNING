<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AttendanceReportService
{
    /** @var array<int, AttendanceStatus> */
    private const ATTENDED_STATUSES = [AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::Excused];

    public function __construct(private AttendanceAccess $access) {}

    /**
     * The percentage is attended (present, late, or excused) qualifying records
     * divided by all qualifying records. A zero-record result is always 0.0.
     *
     * @param  array{school_class_id?: int|null, academic_year_id?: int|null, student_profile_id?: int|null, status?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     * @return array{filters: array, context: array, students: Collection<int, array>, totals: array}
     */
    public function report(User $user, array $filters): array
    {
        abort_unless($user->can('attendance.view'), 403);

        $normalized = [
            'school_class_id' => isset($filters['school_class_id']) ? (int) $filters['school_class_id'] : null,
            'academic_year_id' => isset($filters['academic_year_id']) ? (int) $filters['academic_year_id'] : null,
            'student_profile_id' => isset($filters['student_profile_id']) ? (int) $filters['student_profile_id'] : null,
            'status' => $filters['status'] ?? null,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ];

        $classIds = $this->access->viewableClassIdsForHistory($user, $normalized['academic_year_id']);
        $selectedClass = null;
        if ($normalized['school_class_id']) {
            $selectedClass = SchoolClass::query()->with('academicYear')->findOrFail($normalized['school_class_id']);
            abort_unless($this->access->canViewClassHistory($user, $selectedClass), 403);
            abort_if($normalized['academic_year_id'] && $selectedClass->academic_year_id !== $normalized['academic_year_id'], 404);
            $classIds = collect([$selectedClass->id]);
        }

        $records = AttendanceRecord::query()
            ->select('attendance_records.*')
            ->join('attendance_registers', 'attendance_registers.id', '=', 'attendance_records.attendance_register_id')
            ->whereIn('attendance_registers.school_class_id', $classIds)
            ->when($normalized['academic_year_id'], fn (Builder $query, int $yearId) => $query->whereExists(function ($years) use ($yearId) {
                $years->selectRaw('1')
                    ->from('school_classes')
                    ->whereColumn('school_classes.id', 'attendance_registers.school_class_id')
                    ->where('school_classes.academic_year_id', $yearId);
            }))
            ->when($normalized['student_profile_id'], fn (Builder $query, int $studentId) => $query->where('attendance_records.student_profile_id', $studentId))
            ->when($normalized['status'], fn (Builder $query, string $status) => $query->where('attendance_records.status', $status))
            ->when($normalized['date_from'], fn (Builder $query, string $date) => $query->whereDate('attendance_registers.attendance_date', '>=', $date))
            ->when($normalized['date_to'], fn (Builder $query, string $date) => $query->whereDate('attendance_registers.attendance_date', '<=', $date))
            ->when(! $this->access->isAdministrator($user), fn (Builder $query) => $this->limitToTeacherAssignmentPeriods($query, $user))
            ->with(['studentProfile.user:id,name', 'attendanceRegister.schoolClass.academicYear:id,name'])
            ->orderBy('attendance_registers.attendance_date')
            ->get();

        $students = $records->groupBy('student_profile_id')->map(function (Collection $studentRecords) {
            $first = $studentRecords->first();
            $counts = collect(AttendanceStatus::cases())->mapWithKeys(fn (AttendanceStatus $status) => [$status->value => $studentRecords->where('status', $status)->count()]);
            $total = $studentRecords->count();
            $attended = collect(self::ATTENDED_STATUSES)->sum(fn (AttendanceStatus $status) => $counts[$status->value]);

            return [
                'student' => [
                    'id' => $first->studentProfile->id,
                    'name' => $first->studentProfile->user->name,
                    'student_number' => $first->studentProfile->student_number,
                ],
                'total' => $total,
                'present' => $counts[AttendanceStatus::Present->value],
                'absent' => $counts[AttendanceStatus::Absent->value],
                'late' => $counts[AttendanceStatus::Late->value],
                'excused' => $counts[AttendanceStatus::Excused->value],
                'attendance_percentage' => $total === 0 ? 0.0 : round(($attended / $total) * 100, 2),
            ];
        })->values();

        return [
            'filters' => $normalized,
            'context' => [
                'class_name' => $selectedClass?->name,
                'class_section' => $selectedClass?->section,
                'academic_year_name' => $selectedClass?->academicYear?->name,
            ],
            'students' => $students,
            'totals' => [
                'records' => $records->count(),
                'students' => $students->count(),
                'present' => $records->where('status', AttendanceStatus::Present)->count(),
                'absent' => $records->where('status', AttendanceStatus::Absent)->count(),
                'late' => $records->where('status', AttendanceStatus::Late)->count(),
                'excused' => $records->where('status', AttendanceStatus::Excused)->count(),
            ],
        ];
    }

    private function limitToTeacherAssignmentPeriods(Builder $query, User $user): void
    {
        $query->whereExists(function ($assignments) use ($user) {
            $assignments->selectRaw('1')
                ->from('teacher_class_assignments')
                ->join('teacher_profiles', 'teacher_profiles.id', '=', 'teacher_class_assignments.teacher_profile_id')
                ->where('teacher_profiles.user_id', $user->id)
                ->whereColumn('teacher_class_assignments.school_class_id', 'attendance_registers.school_class_id')
                ->whereColumn('teacher_class_assignments.starts_on', '<=', 'attendance_registers.attendance_date')
                ->where(function ($period) {
                    $period->whereNull('teacher_class_assignments.ends_on')
                        ->orWhereColumn('teacher_class_assignments.ends_on', '>=', 'attendance_registers.attendance_date');
                });
        });
    }
}
