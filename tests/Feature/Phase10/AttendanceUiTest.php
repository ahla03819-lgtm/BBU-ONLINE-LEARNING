<?php

namespace Tests\Feature\Phase10;

use App\Enums\AcademicYearStatus;
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

class AttendanceUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_current_class_teacher_can_access_index_and_open_register(): void
    {
        [$class, $teacher] = $this->classAndTeacher();

        $this->actingAs($teacher)->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->has('classes', fn (Assert $classes) => $classes->where('0.id', $class->id)->where('0.can_open', true))
                ->has('registers', 0));

        $this->actingAs($teacher)->post(route('attendance.registers.store', $class), ['attendance_date' => '2026-09-10'])
            ->assertRedirect(route('attendance.registers.show', [$class, AttendanceRegister::query()->sole()]));
    }

    public function test_unrelated_teacher_and_student_cannot_access_attendance_management_or_another_class_register(): void
    {
        [$class, $teacher, $register] = $this->registerFixture();
        $unrelated = $this->teacher();
        $student = StudentProfile::factory()->create(['user_id' => tap(User::factory()->create(), fn (User $user) => $user->assignRole('Student'))])->user;

        $this->actingAs($unrelated)->get(route('attendance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('classes', 0));
        $this->actingAs($unrelated)->get(route('attendance.registers.show', [$class, $register]))->assertForbidden();
        $this->actingAs($student)->get(route('attendance.index'))->assertForbidden();
        $this->actingAs($student)->get(route('attendance.registers.show', [$class, $register]))->assertForbidden();
    }

    public function test_admin_and_super_admin_receive_policy_derived_management_access(): void
    {
        [$class, $teacher, $register] = $this->registerFixture();

        foreach (['Admin', 'Super Admin'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user)->get(route('attendance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('classes'));
            $this->actingAs($user)->get(route('attendance.registers.show', [$class, $register]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('register.can_record', true)->where('register.can_finalize', true));
        }
    }

    public function test_draft_payload_is_editable_and_does_not_expose_enrollment_or_actor_ids(): void
    {
        [$class, $teacher, $register, $record] = $this->registerFixture();

        $this->actingAs($teacher)->get(route('attendance.registers.show', [$class, $register]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Show')
                ->where('register.status', 'draft')
                ->where('register.can_record', true)
                ->where('register.can_finalize', true)
                ->where('register.records.0.status', AttendanceStatus::Present->value)
                ->where('register.records.0.student.student_number', $record->studentProfile->student_number)
                ->missing('register.records.0.enrollment_id')
                ->missing('register.records.0.recorded_by'));
    }

    public function test_finalized_payload_is_read_only_and_correction_history_is_policy_scoped(): void
    {
        [$class, $teacher, $register, $record] = $this->registerFixture(finalized: true);
        AttendanceRecordRevision::factory()->create([
            'attendance_record_id' => $record,
            'corrected_by' => $teacher,
            'previous_status' => AttendanceStatus::Present,
            'new_status' => AttendanceStatus::Late,
            'correction_reason' => 'Verified arrival time.',
        ]);

        $this->actingAs($teacher)->get(route('attendance.registers.show', [$class, $register]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('register.status', 'finalized')
                ->where('register.can_record', false)
                ->where('register.can_finalize', false)
                ->where('register.records.0.can_correct', true)
                ->has('register.records.0.revisions', 1)
                ->missing('register.records.0.revisions.0.corrected_by.id'));
    }

    /** @return array{SchoolClass, User} */
    private function classAndTeacher(): array
    {
        $year = AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);

        return [$class, $this->teacher($class)];
    }

    /** @return array{SchoolClass, User, AttendanceRegister, AttendanceRecord} */
    private function registerFixture(bool $finalized = false): array
    {
        [$class, $teacher] = $this->classAndTeacher();
        $student = StudentProfile::factory()->create(['user_id' => tap(User::factory()->create(), fn (User $user) => $user->assignRole('Student'))]);
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $student, 'school_class_id' => $class, 'academic_year_id' => $class->academic_year_id, 'current_slot' => 1]);
        $register = AttendanceRegister::factory()->create([
            'school_class_id' => $class,
            'attendance_date' => '2026-09-10',
            'opened_by' => $teacher,
            'status' => $finalized ? 'finalized' : 'draft',
            'finalized_by' => $finalized ? $teacher : null,
            'finalized_at' => $finalized ? now() : null,
        ]);
        $record = AttendanceRecord::factory()->create([
            'attendance_register_id' => $register,
            'student_profile_id' => $student,
            'enrollment_id' => $enrollment,
            'recorded_by' => $teacher,
        ]);

        return [$class, $teacher, $register, $record];
    }

    private function teacher(?SchoolClass $class = null): User
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');
        if ($class) {
            TeacherClassAssignment::factory()->create([
                'teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $teacher]),
                'school_class_id' => $class,
                'current_slot' => 1,
            ]);
        }

        return $teacher;
    }
}
