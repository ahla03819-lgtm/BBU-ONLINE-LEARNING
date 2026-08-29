<?php

namespace Tests\Feature\Phase10;

use App\Enums\AcademicYearStatus;
use App\Enums\AttendanceRegisterStatus;
use App\Enums\AttendanceStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordRevision;
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

class StudentMyAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_student_sees_only_their_own_newest_first_attendance_with_safe_summary(): void
    {
        [$student, $class, $teacher, $ownOld, $ownNew] = $this->fixture();
        $other = $this->recordFor(StudentProfile::factory()->create(['user_id' => tap(User::factory()->create(), fn (User $user) => $user->assignRole('Student'))]), $class, $teacher, '2026-09-12', AttendanceStatus::Excused);
        AttendanceRecordRevision::factory()->create(['attendance_record_id' => $ownNew, 'corrected_by' => $teacher]);

        $this->actingAs($student->user)->get(route('attendance.mine', ['student_id' => $other->student_profile_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/MyAttendance')
                ->has('records', 2)
                ->where('records.0.attendance_date', '2026-09-11')
                ->where('records.0.status', 'late')
                ->where('records.1.attendance_date', '2026-09-10')
                ->where('summary.total', 2)
                ->where('summary.present', 1)
                ->where('summary.late', 1)
                ->missing('records.0.id')
                ->missing('records.0.student_profile_id')
                ->missing('records.0.enrollment_id')
                ->missing('records.0.revisions')
                ->missing('records.0.recorded_by'));
    }

    public function test_student_filters_are_owned_and_server_scoped(): void
    {
        [$student] = $this->fixture();

        $this->actingAs($student->user)->get(route('attendance.mine', ['status' => 'late', 'date_from' => '2026-09-11', 'date_to' => '2026-09-11', 'academic_year_id' => 999999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records', 0)
                ->where('summary.total', 0));

        $yearId = $student->enrollments()->firstOrFail()->academic_year_id;
        $this->actingAs($student->user)->get(route('attendance.mine', ['status' => 'late', 'academic_year_id' => $yearId]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records', 1)
                ->where('records.0.status', 'late')
                ->where('summary.late', 1));
    }

    public function test_student_cannot_access_management_or_mutate_attendance(): void
    {
        [$student, $class, $teacher, $ownOld] = $this->fixture();
        $register = $ownOld->attendanceRegister;

        $this->actingAs($student->user)->get(route('attendance.index'))->assertForbidden();
        $this->actingAs($student->user)->get(route('attendance.registers.show', [$class, $register]))->assertForbidden();
        $this->actingAs($student->user)->post(route('attendance.registers.store', $class), ['attendance_date' => '2026-09-15'])->assertForbidden();
        $this->actingAs($student->user)->patch(route('attendance.records.update', [$class, $register, $ownOld]), ['status' => 'absent'])->assertForbidden();
        $this->actingAs($student->user)->post(route('attendance.registers.finalize', [$class, $register]))->assertForbidden();
        $this->actingAs($student->user)->post(route('attendance.records.correct', [$class, $register, $ownOld]), ['status' => 'absent', 'correction_reason' => 'Not authorized'])->assertForbidden();
    }

    public function test_student_without_attendance_receives_an_empty_read_only_payload_and_staff_ui_remains_available_to_teacher(): void
    {
        $student = StudentProfile::factory()->create(['user_id' => tap(User::factory()->create(), fn (User $user) => $user->assignRole('Student'))]);
        [$otherStudent, $class, $teacher] = $this->fixture();

        $this->actingAs($student->user)->get(route('attendance.mine'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('records', 0)
            ->where('summary.total', 0)
            ->has('academicYears', 0));
        $this->actingAs($teacher)->get(route('attendance.index'))->assertOk();
    }

    /** @return array{StudentProfile, SchoolClass, User, AttendanceRecord, AttendanceRecord} */
    private function fixture(): array
    {
        $year = AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $teacher]), 'school_class_id' => $class, 'current_slot' => 1]);
        $student = StudentProfile::factory()->create(['user_id' => tap(User::factory()->create(), fn (User $user) => $user->assignRole('Student'))]);
        Enrollment::factory()->create(['student_profile_id' => $student, 'academic_year_id' => $year, 'school_class_id' => $class, 'current_slot' => 1]);

        return [$student, $class, $teacher, $this->recordFor($student, $class, $teacher, '2026-09-10', AttendanceStatus::Present), $this->recordFor($student, $class, $teacher, '2026-09-11', AttendanceStatus::Late)];
    }

    private function recordFor(StudentProfile $student, SchoolClass $class, User $teacher, string $date, AttendanceStatus $status): AttendanceRecord
    {
        $enrollment = $student->enrollments()->where('school_class_id', $class->id)->first() ?? Enrollment::factory()->create(['student_profile_id' => $student, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class, 'current_slot' => 1]);
        $register = AttendanceRegister::factory()->create(['school_class_id' => $class, 'attendance_date' => $date, 'status' => AttendanceRegisterStatus::Finalized, 'opened_by' => $teacher, 'finalized_by' => $teacher, 'finalized_at' => now()]);

        return AttendanceRecord::factory()->create(['attendance_register_id' => $register, 'student_profile_id' => $student, 'enrollment_id' => $enrollment, 'status' => $status, 'recorded_by' => $teacher]);
    }
}
