<?php

namespace Tests\Feature\Phase18;

use App\Actions\Attendance\OpenAttendanceRegister;
use App\Enums\AcademicYearStatus;
use App\Enums\AttendanceRegisterStatus;
use App\Enums\AttendanceStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRegister;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_teacher_can_mark_a_draft_register_all_present_and_submit_mixed_statuses_atomically(): void
    {
        [$class, $teacher, $students] = $this->classFixture(2);
        $register = app(OpenAttendanceRegister::class)->handle($teacher, $class, '2026-09-10');
        $records = $register->records()->orderBy('id')->get();

        $this->actingAs($teacher)->patch(route('attendance.records.bulk', [$class, $register]), ['mode' => 'mark_all_present'])
            ->assertSessionHas('success');
        $this->assertSame([AttendanceStatus::Present, AttendanceStatus::Present], $register->records()->orderBy('id')->pluck('status')->all());

        $this->actingAs($teacher)->patch(route('attendance.records.bulk', [$class, $register]), [
            'mode' => 'records',
            'records' => [
                ['id' => $records[0]->id, 'status' => AttendanceStatus::Late->value, 'reason' => 'Traffic'],
                ['id' => $records[1]->id, 'status' => AttendanceStatus::Excused->value, 'reason' => 'Medical'],
            ],
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_records', ['id' => $records[0]->id, 'status' => AttendanceStatus::Late->value, 'reason' => 'Traffic']);
        $this->assertDatabaseHas('attendance_records', ['id' => $records[1]->id, 'status' => AttendanceStatus::Excused->value, 'reason' => 'Medical']);
    }

    public function test_invalid_or_cross_register_bulk_records_roll_back_without_partial_changes(): void
    {
        [$class, $teacher] = $this->classFixture(2);
        $register = app(OpenAttendanceRegister::class)->handle($teacher, $class, '2026-09-10');
        $otherRegister = app(OpenAttendanceRegister::class)->handle($teacher, $class, '2026-09-11');
        $record = $register->records()->firstOrFail();
        $other = $otherRegister->records()->firstOrFail();

        $this->actingAs($teacher)->patch(route('attendance.records.bulk', [$class, $register]), [
            'mode' => 'records',
            'records' => [
                ['id' => $record->id, 'status' => AttendanceStatus::Absent->value],
                ['id' => $other->id, 'status' => AttendanceStatus::Late->value],
            ],
        ])->assertSessionHasErrors('records');

        $this->assertSame(AttendanceStatus::Present, $record->fresh()->status);
        $this->assertSame(AttendanceStatus::Present, $other->fresh()->status);
    }

    public function test_finalized_and_unassigned_teacher_bulk_updates_are_denied(): void
    {
        [$class, $teacher] = $this->classFixture();
        $register = app(OpenAttendanceRegister::class)->handle($teacher, $class, '2026-09-10');
        $register->update(['status' => AttendanceRegisterStatus::Finalized]);
        $unrelated = $this->teacher();

        $this->actingAs($teacher)->patch(route('attendance.records.bulk', [$class, $register]), ['mode' => 'mark_all_present'])->assertForbidden();
        $register->update(['status' => AttendanceRegisterStatus::Draft]);
        $this->actingAs($unrelated)->patch(route('attendance.records.bulk', [$class, $register]), ['mode' => 'mark_all_present'])->assertForbidden();
    }

    public function test_reports_return_server_scoped_aggregates_and_explicit_non_absent_percentage(): void
    {
        [$class, $teacher, $students] = $this->classFixture(2);
        $first = $students[0];
        $second = $students[1];
        $this->createRecord($class, $first, $teacher, '2026-09-10', AttendanceStatus::Present);
        $this->createRecord($class, $first, $teacher, '2026-09-11', AttendanceStatus::Late);
        $this->createRecord($class, $first, $teacher, '2026-09-12', AttendanceStatus::Absent);
        $this->createRecord($class, $first, $teacher, '2026-09-13', AttendanceStatus::Excused);
        $this->createRecord($class, $second, $teacher, '2026-09-12', AttendanceStatus::Absent);

        $this->actingAs($teacher)->getJson(route('attendance.reports', [
            'school_class_id' => $class->id,
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-13',
            'student_profile_id' => $first->id,
        ]))->assertOk()
            ->assertJsonPath('students.0.total', 4)
            ->assertJsonPath('students.0.present', 1)
            ->assertJsonPath('students.0.late', 1)
            ->assertJsonPath('students.0.absent', 1)
            ->assertJsonPath('students.0.excused', 1)
            ->assertJsonPath('students.0.attendance_percentage', 75)
            ->assertJsonPath('totals.records', 4);

        $this->actingAs($teacher)->getJson(route('attendance.reports', ['school_class_id' => $class->id, 'date_from' => '2027-01-01']))
            ->assertOk()->assertJsonPath('students', [])->assertJsonPath('totals.records', 0);
    }

    public function test_reports_respect_historical_assignment_and_enrollment_snapshots(): void
    {
        $year = AcademicYear::factory()->create([
            'status' => AcademicYearStatus::Closed,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'active_slot' => null,
        ]);
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Closed]);
        $teacher = $this->teacher($class, '2026-01-01', '2026-06-30');
        $student = $this->student($class, $year, '2026-02-01', '2026-04-30');
        $record = $this->createRecord($class, $student, $teacher, '2026-04-15', AttendanceStatus::Present);

        $this->actingAs($teacher)->getJson(route('attendance.reports', ['school_class_id' => $class->id]))
            ->assertOk()->assertJsonPath('students.0.student.id', $student->id)->assertJsonPath('totals.records', 1);
        $this->actingAs($teacher)->get(route('attendance.registers.show', [$class, $record->attendanceRegister]))->assertOk();
        $this->actingAs($teacher)->patch(route('attendance.records.bulk', [$class, $record->attendanceRegister]), ['mode' => 'mark_all_present'])->assertForbidden();
    }

    public function test_reports_deny_unrelated_teacher_student_and_cross_class_or_year_access(): void
    {
        [$class, $teacher, $students] = $this->classFixture();
        $record = $this->createRecord($class, $students[0], $teacher, '2026-09-10', AttendanceStatus::Present);
        $unrelated = $this->teacher();
        $student = $students[0]->user;
        $otherYear = AcademicYear::factory()->create(['status' => AcademicYearStatus::Planned]);
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $otherYear]);

        $this->actingAs($unrelated)->getJson(route('attendance.reports', ['school_class_id' => $class->id]))->assertForbidden();
        $this->actingAs($student)->getJson(route('attendance.reports', ['school_class_id' => $class->id]))->assertForbidden();
        $this->actingAs($teacher)->getJson(route('attendance.reports', ['school_class_id' => $otherClass->id]))->assertForbidden();
        $this->actingAs($teacher)->getJson(route('attendance.reports', ['school_class_id' => $class->id, 'academic_year_id' => $otherYear->id]))->assertNotFound();
        $this->assertSame(AttendanceStatus::Present, $record->fresh()->status);
    }

    public function test_reports_preserve_multi_class_and_ended_enrollment_snapshots_across_date_ranges(): void
    {
        $year = AcademicYear::factory()->create([
            'status' => AcademicYearStatus::Active,
            'active_slot' => 1,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ]);
        $firstClass = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);
        $secondClass = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);
        $teacher = $this->teacher($firstClass);
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $teacher->teacherProfile,
            'school_class_id' => $secondClass,
            'starts_on' => '2026-01-01',
            'current_slot' => 1,
        ]);
        $studentUser = User::factory()->create();
        $studentUser->assignRole('Student');
        $student = StudentProfile::factory()->create(['user_id' => $studentUser]);
        Enrollment::factory()->create([
            'student_profile_id' => $student,
            'academic_year_id' => $year,
            'school_class_id' => $firstClass,
            'enrolled_on' => '2026-01-01',
            'ended_on' => '2026-03-31',
            'current_slot' => null,
        ]);
        Enrollment::factory()->create([
            'student_profile_id' => $student,
            'academic_year_id' => $year,
            'school_class_id' => $secondClass,
            'enrolled_on' => '2026-04-01',
            'current_slot' => 1,
        ]);
        $this->createRecord($firstClass, $student, $teacher, '2026-03-30', AttendanceStatus::Present);
        $this->createRecord($secondClass, $student, $teacher, '2026-04-02', AttendanceStatus::Absent);

        $this->actingAs($teacher)->getJson(route('attendance.reports', [
            'academic_year_id' => $year->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-03-31',
        ]))->assertOk()
            ->assertJsonPath('totals.records', 1)
            ->assertJsonPath('students.0.present', 1);

        $this->actingAs($teacher)->getJson(route('attendance.reports', [
            'school_class_id' => $secondClass->id,
            'academic_year_id' => $year->id,
            'status' => AttendanceStatus::Absent->value,
        ]))->assertOk()
            ->assertJsonPath('totals.records', 1)
            ->assertJsonPath('students.0.absent', 1)
            ->assertJsonPath('students.0.attendance_percentage', 0);
    }

    public function test_staff_attendance_pages_receive_authorized_history_filters_student_options_and_immutable_register_capabilities(): void
    {
        [$class, $teacher, $students] = $this->classFixture(2);
        $register = app(OpenAttendanceRegister::class)->handle($teacher, $class, '2026-09-10');

        $this->actingAs($teacher)->get(route('attendance.index', [
            'school_class_id' => $class->id,
            'academic_year_id' => $class->academic_year_id,
            'student_profile_id' => $students[0]->id,
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/Index')
            ->has('classes', 1)
            ->has('students', 2)
            ->where('filters.school_class_id', $class->id)
            ->where('filters.student_profile_id', $students[0]->id)
            ->where('report_url', route('attendance.reports')));

        $register->update(['status' => AttendanceRegisterStatus::Finalized]);
        $this->actingAs($teacher)->get(route('attendance.registers.show', [$class, $register]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Show')
                ->where('register.status', AttendanceRegisterStatus::Finalized->value)
                ->where('register.can_record', false)
                ->where('register.can_finalize', false));
    }

    public function test_csv_export_streams_the_same_authorized_report_aggregates_with_safe_cells_and_filename(): void
    {
        [$class, $teacher, $students] = $this->classFixture(2);
        $class->update(['name' => 'Grade 1A', 'section' => 'Blue']);
        $class->academicYear->update(['name' => '2026-2027']);
        $students[0]->update(['student_number' => '-001']);
        $students[0]->user->update(['name' => '=SUM(1,1)']);
        $this->createRecord($class, $students[0], $teacher, '2026-09-10', AttendanceStatus::Present);
        $this->createRecord($class, $students[0], $teacher, '2026-09-11', AttendanceStatus::Absent);
        $this->createRecord($class, $students[1], $teacher, '2026-09-11', AttendanceStatus::Late);

        $response = $this->actingAs($teacher)->get(route('attendance.reports.export', [
            'school_class_id' => $class->id,
            'academic_year_id' => $class->academic_year_id,
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-11',
            'student_profile_id' => $students[0]->id,
        ]));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('attendance-report-grade-1a-2026-09-10-2026-09-11.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('"Student identifier","Student name","Academic year",Class,"Date range","Total records",Present,Absent,Late,Excused,"Attendance percentage"', $csv);
        $this->assertStringContainsString("'-001", $csv);
        $this->assertStringContainsString("'=SUM(1,1)", $csv);
        $this->assertStringContainsString('2,1,1,0,0,50.00%', $csv);
        $this->assertSame(2, $this->actingAs($teacher)->getJson(route('attendance.reports', [
            'school_class_id' => $class->id,
            'student_profile_id' => $students[0]->id,
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-11',
        ]))->json('students.0.total'));
    }

    public function test_csv_export_preserves_report_authorization_for_historical_teachers_administrators_and_denied_users(): void
    {
        [$class, $teacher, $students] = $this->classFixture();
        $this->createRecord($class, $students[0], $teacher, '2026-09-10', AttendanceStatus::Present);
        $unrelated = $this->teacher();
        $student = $students[0]->user;
        $otherYear = AcademicYear::factory()->create(['status' => AcademicYearStatus::Planned]);
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $otherYear]);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $this->actingAs($unrelated)->get(route('attendance.reports.export', ['school_class_id' => $class->id]))->assertForbidden();
        $this->actingAs($student)->get(route('attendance.reports.export', ['school_class_id' => $class->id]))->assertForbidden();
        $this->actingAs($teacher)->get(route('attendance.reports.export', ['school_class_id' => $otherClass->id]))->assertForbidden();
        $this->actingAs($teacher)->get(route('attendance.reports.export', ['school_class_id' => $class->id, 'academic_year_id' => $otherYear->id]))->assertNotFound();
        $this->actingAs($admin)->get(route('attendance.reports.export', ['school_class_id' => $class->id]))->assertOk()->assertDownload();
        $this->actingAs($superAdmin)->get(route('attendance.reports.export', ['school_class_id' => $class->id]))->assertOk()->assertDownload();
    }

    public function test_csv_export_allows_legitimate_historical_teacher_access_and_safe_zero_record_output(): void
    {
        $year = AcademicYear::factory()->create(['status' => AcademicYearStatus::Closed, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'active_slot' => null]);
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Closed]);
        $teacher = $this->teacher($class, '2026-01-01', '2026-06-30');
        $student = $this->student($class, $year, '2026-02-01', '2026-04-30');
        $this->createRecord($class, $student, $teacher, '2026-04-15', AttendanceStatus::Present);

        $this->actingAs($teacher)->get(route('attendance.reports.export', ['school_class_id' => $class->id]))
            ->assertOk()->assertDownload();
        $empty = $this->actingAs($teacher)->get(route('attendance.reports.export', ['school_class_id' => $class->id, 'date_from' => '2027-01-01']));
        $empty->assertOk()->assertDownload();
        $this->assertSame(1, substr_count($empty->streamedContent(), "\n"));
    }

    /** @return array{SchoolClass, User, array<int, StudentProfile>} */
    private function classFixture(int $studentCount = 1): array
    {
        $year = AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);
        $teacher = $this->teacher($class);
        $students = collect(range(1, $studentCount))->map(fn () => $this->student($class, $year))->all();

        return [$class, $teacher, $students];
    }

    private function teacher(?SchoolClass $class = null, string $startsOn = '2026-01-01', ?string $endsOn = null): User
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');
        if ($class) {
            TeacherClassAssignment::factory()->create([
                'teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $teacher]),
                'school_class_id' => $class,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'current_slot' => $endsOn ? null : 1,
            ]);
        }

        return $teacher;
    }

    private function student(SchoolClass $class, AcademicYear $year, string $enrolledOn = '2026-01-01', ?string $endedOn = null): StudentProfile
    {
        $user = User::factory()->create();
        $user->assignRole('Student');
        $student = StudentProfile::factory()->create(['user_id' => $user]);
        Enrollment::factory()->create([
            'student_profile_id' => $student,
            'academic_year_id' => $year,
            'school_class_id' => $class,
            'enrolled_on' => $enrolledOn,
            'ended_on' => $endedOn,
            'current_slot' => $endedOn ? null : 1,
        ]);

        return $student;
    }

    private function createRecord(SchoolClass $class, StudentProfile $student, User $teacher, string $date, AttendanceStatus $status): AttendanceRecord
    {
        $register = AttendanceRegister::query()
            ->where('school_class_id', $class->id)
            ->whereDate('attendance_date', $date)
            ->first() ?? AttendanceRegister::factory()->create([
                'school_class_id' => $class,
                'attendance_date' => $date,
                'opened_by' => $teacher,
            ]);
        $enrollment = $student->enrollments()->where('school_class_id', $class->id)->firstOrFail();

        return AttendanceRecord::factory()->create([
            'attendance_register_id' => $register,
            'student_profile_id' => $student,
            'enrollment_id' => $enrollment,
            'status' => $status,
            'recorded_by' => $teacher,
        ]);
    }
}
