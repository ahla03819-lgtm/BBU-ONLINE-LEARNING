<?php

namespace App\Http\Controllers;

use App\Actions\Attendance\CorrectAttendanceRecord;
use App\Actions\Attendance\FinalizeAttendanceRegister;
use App\Actions\Attendance\OpenAttendanceRegister;
use App\Actions\Attendance\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Http\Requests\Attendance\CorrectAttendanceRecordRequest;
use App\Http\Requests\Attendance\OpenAttendanceRegisterRequest;
use App\Http\Requests\Attendance\RecordAttendanceRequest;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRegister;
use App\Models\SchoolClass;
use App\Services\AttendanceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(Request $request, AttendanceAccess $access): Response
    {
        $user = $request->user();
        abort_unless($user->can('attendance.view'), 403);

        $classes = SchoolClass::query()
            ->with(['academicYear:id,name,status', 'gradeLevel:id,name'])
            ->orderBy('name')
            ->get()
            ->filter(fn (SchoolClass $schoolClass) => $access->canViewRegister($user, $schoolClass))
            ->values();
        $classIds = $classes->pluck('id');
        $filters = [
            'school_class_id' => $request->integer('school_class_id') ?: null,
            'attendance_date' => $request->string('attendance_date')->toString() ?: null,
        ];

        $registers = AttendanceRegister::query()
            ->whereIn('school_class_id', $classIds)
            ->when($filters['school_class_id'], fn ($query, int $classId) => $query->where('school_class_id', $classId))
            ->when($filters['attendance_date'], fn ($query, string $date) => $query->whereDate('attendance_date', $date))
            ->with(['schoolClass.academicYear:id,name,status', 'schoolClass.gradeLevel:id,name'])
            ->withCount('records')
            ->withCount([
                'records as present_count' => fn ($query) => $query->where('status', AttendanceStatus::Present->value),
                'records as absent_count' => fn ($query) => $query->where('status', AttendanceStatus::Absent->value),
                'records as late_count' => fn ($query) => $query->where('status', AttendanceStatus::Late->value),
                'records as excused_count' => fn ($query) => $query->where('status', AttendanceStatus::Excused->value),
            ])
            ->latest('attendance_date')
            ->latest('id')
            ->get()
            ->filter(fn (AttendanceRegister $register) => $user->can('view', $register))
            ->map(fn (AttendanceRegister $register) => $this->registerSummary($register))
            ->values();

        return Inertia::render('Attendance/Index', [
            'classes' => $classes->map(fn (SchoolClass $schoolClass) => [
                'id' => $schoolClass->id,
                'name' => $schoolClass->name,
                'section' => $schoolClass->section,
                'academic_year' => $schoolClass->academicYear?->only('name'),
                'grade_level' => $schoolClass->gradeLevel?->only('name'),
                'can_open' => $user->can('record', [AttendanceRegister::class, $schoolClass]),
            ])->values(),
            'registers' => $registers,
            'filters' => $filters,
        ]);
    }

    public function myAttendance(Request $request): Response
    {
        $user = $request->user();
        $student = $user->studentProfile;
        abort_unless($student && $user->can('attendance.view-own'), 403);

        $allowedStatuses = array_column(AttendanceStatus::cases(), 'value');
        $filters = [
            'date_from' => $request->string('date_from')->toString() ?: null,
            'date_to' => $request->string('date_to')->toString() ?: null,
            'status' => in_array($request->string('status')->toString(), $allowedStatuses, true) ? $request->string('status')->toString() : null,
            'academic_year_id' => $request->integer('academic_year_id') ?: null,
        ];
        $records = AttendanceRecord::query()
            ->where('student_profile_id', $student->id)
            ->when($filters['date_from'], fn ($query, string $date) => $query->whereHas('attendanceRegister', fn ($registers) => $registers->whereDate('attendance_date', '>=', $date)))
            ->when($filters['date_to'], fn ($query, string $date) => $query->whereHas('attendanceRegister', fn ($registers) => $registers->whereDate('attendance_date', '<=', $date)))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['academic_year_id'], fn ($query, int $yearId) => $query->whereHas('attendanceRegister.schoolClass', fn ($classes) => $classes->where('academic_year_id', $yearId)))
            ->with(['attendanceRegister.schoolClass.academicYear:id,name', 'attendanceRegister.schoolClass.gradeLevel:id,name'])
            ->get()
            ->filter(fn (AttendanceRecord $record) => $user->can('view', $record))
            ->sortByDesc(fn (AttendanceRecord $record) => $record->attendanceRegister->attendance_date)
            ->values();
        $academicYears = AttendanceRecord::query()
            ->where('student_profile_id', $student->id)
            ->with('attendanceRegister.schoolClass.academicYear:id,name')
            ->get()
            ->filter(fn (AttendanceRecord $record) => $user->can('view', $record))
            ->map(fn (AttendanceRecord $record) => $record->attendanceRegister->schoolClass->academicYear)
            ->filter()
            ->unique('id')
            ->sortByDesc('name')
            ->map(fn ($year) => $year->only('id', 'name'))
            ->values();

        return Inertia::render('Attendance/MyAttendance', [
            'records' => $records->map(fn (AttendanceRecord $record) => [
                'attendance_date' => $record->attendanceRegister->attendance_date->toDateString(),
                'status' => $record->status->value,
                'reason' => $record->reason,
                'register_finalized' => $record->attendanceRegister->status->value === 'finalized',
                'school_class' => [
                    'name' => $record->attendanceRegister->schoolClass->name,
                    'section' => $record->attendanceRegister->schoolClass->section,
                    'grade_level' => $record->attendanceRegister->schoolClass->gradeLevel?->only('name'),
                    'academic_year' => $record->attendanceRegister->schoolClass->academicYear?->only('name'),
                ],
            ])->values(),
            'summary' => [
                'total' => $records->count(),
                'present' => $records->where('status', AttendanceStatus::Present)->count(),
                'absent' => $records->where('status', AttendanceStatus::Absent)->count(),
                'late' => $records->where('status', AttendanceStatus::Late)->count(),
                'excused' => $records->where('status', AttendanceStatus::Excused)->count(),
            ],
            'academicYears' => $academicYears,
            'filters' => $filters,
        ]);
    }

    public function show(SchoolClass $schoolClass, AttendanceRegister $attendanceRegister): Response
    {
        $this->assertScope($schoolClass, $attendanceRegister);
        $this->authorize('view', $attendanceRegister);
        $user = request()->user();
        $attendanceRegister->load([
            'schoolClass.academicYear:id,name,status',
            'schoolClass.gradeLevel:id,name',
            'finalizedBy:id,name',
            'records.studentProfile.user:id,name',
            'records.revisions.correctedBy:id,name',
        ]);

        return Inertia::render('Attendance/Show', [
            'schoolClass' => [
                'id' => $schoolClass->id,
                'name' => $schoolClass->name,
                'section' => $schoolClass->section,
                'academic_year' => $schoolClass->academicYear?->only('name'),
                'grade_level' => $schoolClass->gradeLevel?->only('name'),
            ],
            'register' => [
                ...$this->registerSummary($attendanceRegister),
                'finalized_at' => $attendanceRegister->finalized_at?->toIso8601String(),
                'finalized_by' => $attendanceRegister->finalizedBy?->only('name'),
                'can_record' => $user->can('record', $attendanceRegister),
                'can_finalize' => $user->can('finalize', $attendanceRegister),
                'records' => $attendanceRegister->records->map(fn (AttendanceRecord $record) => [
                    'id' => $record->id,
                    'student' => [
                        'name' => $record->studentProfile->user->name,
                        'student_number' => $record->studentProfile->student_number,
                    ],
                    'status' => $record->status->value,
                    'reason' => $record->reason,
                    'can_correct' => $user->can('correct', $record),
                    'revisions' => $record->revisions
                        ->filter(fn ($revision) => $user->can('view', $revision))
                        ->sortByDesc('corrected_at')
                        ->map(fn ($revision) => [
                            'previous_status' => $revision->previous_status->value,
                            'previous_reason' => $revision->previous_reason,
                            'new_status' => $revision->new_status->value,
                            'new_reason' => $revision->new_reason,
                            'correction_reason' => $revision->correction_reason,
                            'corrected_by' => $revision->correctedBy?->only('name'),
                            'corrected_at' => $revision->corrected_at?->toIso8601String(),
                        ])->values(),
                ])->values(),
            ],
        ]);
    }

    public function store(OpenAttendanceRegisterRequest $request, SchoolClass $schoolClass, OpenAttendanceRegister $action): RedirectResponse
    {
        $register = $action->handle($request->user(), $schoolClass, $request->validated('attendance_date'));

        return redirect()->route('attendance.registers.show', [$schoolClass, $register])
            ->with('success', "Attendance register {$register->attendance_date->toDateString()} is ready.");
    }

    public function update(RecordAttendanceRequest $request, SchoolClass $schoolClass, AttendanceRegister $attendanceRegister, AttendanceRecord $record, RecordAttendance $action): RedirectResponse
    {
        $this->assertScope($schoolClass, $attendanceRegister, $record);
        $data = $request->validated();
        $action->handle($request->user(), $attendanceRegister, $record, AttendanceStatus::from($data['status']), $data['reason'] ?? null);

        return back()->with('success', 'Attendance recorded.');
    }

    public function finalize(SchoolClass $schoolClass, AttendanceRegister $attendanceRegister, FinalizeAttendanceRegister $action): RedirectResponse
    {
        $this->assertScope($schoolClass, $attendanceRegister);
        $action->handle(request()->user(), $attendanceRegister);

        return back()->with('success', 'Attendance register finalized.');
    }

    public function correct(CorrectAttendanceRecordRequest $request, SchoolClass $schoolClass, AttendanceRegister $attendanceRegister, AttendanceRecord $record, CorrectAttendanceRecord $action): RedirectResponse
    {
        $this->assertScope($schoolClass, $attendanceRegister, $record);
        $data = $request->validated();
        $action->handle($request->user(), $attendanceRegister, $record, AttendanceStatus::from($data['status']), $data['reason'] ?? null, $data['correction_reason']);

        return back()->with('success', 'Attendance correction recorded.');
    }

    private function assertScope(SchoolClass $schoolClass, AttendanceRegister $register, ?AttendanceRecord $record = null): void
    {
        abort_unless($register->school_class_id === $schoolClass->id && (! $record || $record->attendance_register_id === $register->id), 404);
    }

    private function registerSummary(AttendanceRegister $register): array
    {
        $register->loadMissing(['schoolClass.academicYear:id,name,status', 'schoolClass.gradeLevel:id,name']);

        return [
            'public_id' => $register->public_id,
            'attendance_date' => $register->attendance_date->toDateString(),
            'status' => $register->status->value,
            'school_class' => [
                'id' => $register->schoolClass->id,
                'name' => $register->schoolClass->name,
                'section' => $register->schoolClass->section,
                'academic_year' => $register->schoolClass->academicYear?->only('name'),
                'grade_level' => $register->schoolClass->gradeLevel?->only('name'),
            ],
            'total_roster_count' => $register->records_count ?? $register->records()->count(),
            'present_count' => $register->present_count ?? $register->records()->where('status', AttendanceStatus::Present->value)->count(),
            'absent_count' => $register->absent_count ?? $register->records()->where('status', AttendanceStatus::Absent->value)->count(),
            'late_count' => $register->late_count ?? $register->records()->where('status', AttendanceStatus::Late->value)->count(),
            'excused_count' => $register->excused_count ?? $register->records()->where('status', AttendanceStatus::Excused->value)->count(),
        ];
    }
}
