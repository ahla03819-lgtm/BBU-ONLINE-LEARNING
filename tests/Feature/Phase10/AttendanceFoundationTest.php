<?php

namespace Tests\Feature\Phase10;

use App\Enums\AcademicYearStatus;
use App\Enums\AccountStatus;
use App\Enums\AttendanceStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordRevision;
use App\Models\AttendanceRegister;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AttendanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_register_record_and_revision_relationships_use_public_register_identifiers(): void
    {
        [$class, $student, $enrollment] = $this->studentInActiveClass();
        $teacher = $this->classTeacher($class);
        $register = AttendanceRegister::factory()->create(['school_class_id' => $class, 'opened_by' => $teacher]);
        $record = $this->record($register, $student, $enrollment, $teacher);
        $revision = AttendanceRecordRevision::factory()->create(['attendance_record_id' => $record, 'corrected_by' => $teacher]);

        $this->assertSame('public_id', $register->getRouteKeyName());
        $this->assertTrue($register->schoolClass->is($class));
        $this->assertTrue($register->records->sole()->is($record));
        $this->assertTrue($record->studentProfile->is($student));
        $this->assertTrue($record->enrollment->is($enrollment));
        $this->assertTrue($record->revisions->sole()->is($revision));
        $this->assertTrue($revision->correctedBy->is($teacher));
    }

    public function test_database_enforces_one_register_per_class_and_date(): void
    {
        [$class, $student, $enrollment] = $this->studentInActiveClass();
        $register = AttendanceRegister::factory()->create(['school_class_id' => $class, 'attendance_date' => '2026-09-01']);

        $this->expectException(QueryException::class);
        AttendanceRegister::factory()->create(['school_class_id' => $class, 'attendance_date' => '2026-09-01']);

    }

    public function test_database_enforces_one_record_per_student_per_register(): void
    {
        [$class, $student, $enrollment] = $this->studentInActiveClass();
        $register = AttendanceRegister::factory()->create(['school_class_id' => $class]);
        $this->record($register, $student, $enrollment, User::factory()->create());

        $this->expectException(QueryException::class);
        AttendanceRecord::factory()->create([
            'attendance_register_id' => $register,
            'student_profile_id' => $student,
            'enrollment_id' => $enrollment,
        ]);
    }

    public function test_attendance_statuses_are_constrained_to_the_domain_enum(): void
    {
        $this->assertSame(['present', 'absent', 'late', 'excused'], array_column(AttendanceStatus::cases(), 'value'));
        $record = AttendanceRecord::factory()->create(['status' => AttendanceStatus::Late]);

        $this->assertSame(AttendanceStatus::Late, $record->status);
    }

    public function test_revisions_are_append_only_and_preserve_before_and_after_values(): void
    {
        $revision = AttendanceRecordRevision::factory()->create();

        $this->assertSame(AttendanceStatus::Absent, $revision->previous_status);
        $this->assertSame(AttendanceStatus::Excused, $revision->new_status);
        $this->assertNotEmpty($revision->correction_reason);

        $revision->new_reason = 'Changed after the fact';
        try {
            $revision->save();
            $this->fail('Attendance revisions must not be updated.');
        } catch (LogicException) {
            $this->assertSame('Verified medical reason', $revision->fresh()->new_reason);
        }
    }

    public function test_granular_permissions_and_deployment_verifier_match_the_approved_matrix(): void
    {
        $matrix = [
            'Super Admin' => ['attendance.view', 'attendance.view-own', 'attendance.record', 'attendance.finalize', 'attendance.correct'],
            'Admin' => ['attendance.view', 'attendance.view-own', 'attendance.record', 'attendance.finalize', 'attendance.correct'],
            'Teacher' => ['attendance.view', 'attendance.record', 'attendance.finalize', 'attendance.correct'],
            'Student' => ['attendance.view-own'],
        ];

        foreach ($matrix as $role => $expected) {
            $user = User::factory()->create();
            $user->assignRole($role);
            foreach (['attendance.view', 'attendance.view-own', 'attendance.record', 'attendance.finalize', 'attendance.correct'] as $permission) {
                $this->assertSame(in_array($permission, $expected, true), $user->can($permission));
            }
        }

        $this->artisan('attendance:verify-permissions')->assertSuccessful();
        Permission::findByName('attendance.correct')->delete();
        $this->artisan('attendance:verify-permissions')
            ->expectsOutputToContain('Attendance RBAC is not synchronized.')
            ->assertFailed();
    }

    public function test_current_class_teacher_can_manage_only_their_active_class_and_subject_teacher_is_denied(): void
    {
        [$class] = $this->studentInActiveClass();
        $classTeacher = $this->classTeacher($class);
        $subjectTeacher = User::factory()->create();
        $subjectTeacher->assignRole('Teacher');
        $subject = ClassSubject::factory()->create(['school_class_id' => $class]);
        TeacherClassSubjectAssignment::factory()->create([
            'teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $subjectTeacher]),
            'class_subject_id' => $subject,
            'current_slot' => 1,
        ]);
        $register = AttendanceRegister::factory()->create(['school_class_id' => $class]);

        $this->assertTrue($classTeacher->can('record', $register));
        $this->assertTrue($classTeacher->can('finalize', $register));
        $this->assertFalse($subjectTeacher->can('record', $register));
        $this->assertFalse($subjectTeacher->can('finalize', $register));
    }

    public function test_historical_teacher_inactive_scope_and_unverified_accounts_cannot_mutate(): void
    {
        [$class] = $this->studentInActiveClass();
        $historicalTeacher = User::factory()->create();
        $historicalTeacher->assignRole('Teacher');
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $historicalTeacher]),
            'school_class_id' => $class,
            'ends_on' => now()->subDay()->toDateString(),
            'current_slot' => null,
        ]);
        $unverifiedTeacher = $this->classTeacher($class, false);
        $register = AttendanceRegister::factory()->create(['school_class_id' => $class]);

        $this->assertFalse($historicalTeacher->can('record', $register));
        $this->assertFalse($unverifiedTeacher->can('record', $register));

        $unverifiedTeacher->update(['email_verified_at' => now(), 'status' => AccountStatus::Inactive]);
        $this->assertFalse($unverifiedTeacher->can('record', $register));

        $class->update(['status' => SchoolClassStatus::Closed]);
        $this->assertFalse($unverifiedTeacher->can('record', $register->fresh()));
    }

    public function test_students_can_view_only_their_own_records_and_cross_class_resources_are_denied(): void
    {
        [$class, $student, $enrollment] = $this->studentInActiveClass();
        [$otherStudent, $otherEnrollment] = $this->studentInClass($class);
        $teacher = $this->classTeacher($class);
        $register = AttendanceRegister::factory()->create(['school_class_id' => $class]);
        $ownRecord = $this->record($register, $student, $enrollment, $teacher);
        $otherRecord = $this->record($register, $otherStudent, $otherEnrollment, $teacher);
        $otherClass = SchoolClass::factory()->create([
            'academic_year_id' => $class->academic_year_id,
            'status' => SchoolClassStatus::Active,
        ]);
        $otherRegister = AttendanceRegister::factory()->create(['school_class_id' => $otherClass]);

        $this->assertTrue($student->user->can('view', $ownRecord));
        $this->assertFalse($student->user->can('view', $otherRecord));
        $this->assertFalse($teacher->can('view', $otherRegister));
        $this->assertFalse($teacher->can('record', $otherRegister));
    }

    private function activeClass(): SchoolClass
    {
        $year = AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);

        return SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);
    }

    /** @return array{SchoolClass, StudentProfile, Enrollment} */
    private function studentInActiveClass(): array
    {
        $class = $this->activeClass();

        return [$class, ...$this->studentInClass($class)];
    }

    /** @return array{StudentProfile, Enrollment} */
    private function studentInClass(SchoolClass $class): array
    {
        $studentUser = User::factory()->create();
        $studentUser->assignRole('Student');
        $student = StudentProfile::factory()->create(['user_id' => $studentUser]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class,
            'current_slot' => 1,
        ]);

        return [$student, $enrollment];
    }

    private function classTeacher(SchoolClass $class, bool $verified = true): User
    {
        $teacher = $verified ? User::factory()->create() : User::factory()->unverified()->create();
        $teacher->assignRole('Teacher');
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $teacher]),
            'school_class_id' => $class,
            'current_slot' => 1,
        ]);

        return $teacher;
    }

    private function record(AttendanceRegister $register, StudentProfile $student, Enrollment $enrollment, User $teacher): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'attendance_register_id' => $register,
            'student_profile_id' => $student,
            'enrollment_id' => $enrollment,
            'recorded_by' => $teacher,
        ]);
    }
}
