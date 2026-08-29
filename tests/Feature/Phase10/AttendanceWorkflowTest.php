<?php

namespace Tests\Feature\Phase10;

use App\Actions\Attendance\CorrectAttendanceRecord;
use App\Actions\Attendance\FinalizeAttendanceRegister;
use App\Actions\Attendance\OpenAttendanceRegister;
use App\Actions\Attendance\RecordAttendance;
use App\Enums\AcademicYearStatus;
use App\Enums\AttendanceRegisterStatus;
use App\Enums\AttendanceStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AttendanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_opening_is_idempotent_snapshots_only_current_date_eligible_enrollments_and_audits(): void
    {
        [$class, $teacher, $current, $historical] = $this->fixture();
        $this->actingAs($teacher);
        $action = app(OpenAttendanceRegister::class);
        $register = $action->handle($teacher, $class, '2026-09-10');
        $retry = $action->handle($teacher, $class, '2026-09-10');

        $this->assertSame($register->id, $retry->id);
        $this->assertSame(1, $register->records()->count());
        $this->assertSame($current->student_profile_id, $register->records()->sole()->student_profile_id);
        $this->assertFalse($register->records()->where('student_profile_id', $historical->student_profile_id)->exists());
        $this->assertSame(AttendanceStatus::Present, $register->records()->sole()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.register-opened', 'actor_id' => $teacher->id]);
    }

    public function test_recording_finalizing_and_correcting_preserve_server_owned_history(): void
    {
        [$class, $teacher] = $this->fixture();
        $this->actingAs($teacher);
        $register = app(OpenAttendanceRegister::class)->handle($teacher, $class, '2026-09-10');
        $record = $register->records()->sole();
        app(RecordAttendance::class)->handle($teacher, $register, $record, AttendanceStatus::Late, 'Traffic');
        $finalized = app(FinalizeAttendanceRegister::class)->handle($teacher, $register);

        $this->assertSame(AttendanceRegisterStatus::Finalized, $finalized->status);
        $this->assertSame($teacher->id, $finalized->finalized_by);
        $this->assertNotNull($finalized->finalized_at);
        $this->expectException(AuthorizationException::class);
        app(RecordAttendance::class)->handle($teacher, $finalized, $record, AttendanceStatus::Absent, 'No longer allowed');
    }

    public function test_finalized_correction_creates_append_only_revision_and_audit(): void
    {
        [$class, $teacher] = $this->fixture();
        $this->actingAs($teacher);
        $register = app(OpenAttendanceRegister::class)->handle($teacher, $class, '2026-09-10');
        $record = $register->records()->sole();
        app(FinalizeAttendanceRegister::class)->handle($teacher, $register);
        $corrected = app(CorrectAttendanceRecord::class)->handle($teacher, $register, $record, AttendanceStatus::Excused, 'Medical certificate', 'Family supplied evidence');

        $revision = $corrected->revisions()->sole();
        $this->assertSame(AttendanceStatus::Present, $revision->previous_status);
        $this->assertSame(AttendanceStatus::Excused, $revision->new_status);
        $this->assertSame($teacher->id, $revision->corrected_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.record-corrected', 'actor_id' => $teacher->id]);
        $this->expectException(ValidationException::class);
        app(CorrectAttendanceRecord::class)->handle($teacher, $register, $corrected, AttendanceStatus::Absent, null, '');
    }

    public function test_subject_only_historical_and_student_users_cannot_mutate_or_cross_class_records(): void
    {
        [$class, $teacher] = $this->fixture();
        $this->actingAs($teacher);
        $register = app(OpenAttendanceRegister::class)->handle($teacher, $class, '2026-09-10');
        $record = $register->records()->sole();
        $subjectTeacher = User::factory()->create();
        $subjectTeacher->assignRole('Teacher');
        TeacherClassSubjectAssignment::factory()->create(['teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $subjectTeacher]), 'class_subject_id' => ClassSubject::factory()->create(['school_class_id' => $class])]);
        $student = $record->studentProfile->user;

        foreach ([$subjectTeacher, $student] as $user) {
            try {
                app(RecordAttendance::class)->handle($user, $register, $record, AttendanceStatus::Absent, null);
                $this->fail('Unauthorized mutation succeeded.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $otherRegister = app(OpenAttendanceRegister::class)->handle($teacher, $class, '2026-09-11');
        $this->expectException(NotFoundHttpException::class);
        app(RecordAttendance::class)->handle($teacher, $otherRegister, $record, AttendanceStatus::Absent, null);
    }

    /** @return array{SchoolClass, User, Enrollment, Enrollment} */
    private function fixture(): array
    {
        $year = AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $teacher]), 'school_class_id' => $class]);
        $student = StudentProfile::factory()->create(['user_id' => tap(User::factory()->create(), fn ($user) => $user->assignRole('Student'))]);
        $current = Enrollment::factory()->create(['student_profile_id' => $student, 'academic_year_id' => $year, 'school_class_id' => $class, 'enrolled_on' => '2026-09-01']);
        $historical = Enrollment::factory()->create(['student_profile_id' => StudentProfile::factory(), 'academic_year_id' => $year, 'school_class_id' => $class, 'enrolled_on' => '2026-08-01', 'ended_on' => '2026-09-01', 'current_slot' => null]);

        return [$class, $teacher, $current, $historical];
    }
}
