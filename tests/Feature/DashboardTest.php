<?php

namespace Tests\Feature;

use App\Actions\People\SaveProfile;
use App\Enums\AcademicYearStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_receives_global_operational_metrics(): void
    {
        $admin = $this->user('Admin');
        $this->classSubject();

        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('variant', 'admin')->has('metrics', 4)->has('quickActions'));
    }

    public function test_super_admin_receives_the_broadest_administrative_dashboard(): void
    {
        $superAdmin = $this->user('Super Admin');
        $this->classSubject();

        $this->actingAs($superAdmin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('variant', 'super-admin')
            ->has('metrics', 4));
    }

    public function test_admin_with_a_teacher_profile_keeps_administrative_context_and_effective_role_label(): void
    {
        $admin = $this->user('Admin');
        $admin->assignRole('Teacher');
        TeacherProfile::factory()->create(['user_id' => $admin->id]);
        $this->classSubject();

        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('variant', 'admin')
            ->where('auth.role_label', 'Admin')
            ->has('metrics', 4));
    }

    public function test_saving_a_teacher_profile_does_not_replace_an_existing_administrative_role(): void
    {
        $admin = $this->user('Admin');
        $profile = TeacherProfile::factory()->make(['user_id' => $admin->id]);
        $profile->setRelation('user', $admin);

        app(SaveProfile::class)->handle($profile, $profile->getAttributes(), 'teacher-profile', 'Teacher');

        $this->assertTrue($admin->fresh()->hasRole('Admin'));
        $this->assertTrue($admin->fresh()->hasRole('Teacher'));
        $this->assertSame('Admin', $admin->fresh()->effectiveRole());
    }

    public function test_teacher_only_receives_current_assigned_class_data(): void
    {
        [$class, $subject] = $this->classSubject();
        $teacher = $this->user('Teacher');
        TeacherClassSubjectAssignment::factory()->create(['teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $teacher]), 'class_subject_id' => $subject, 'current_slot' => 1]);
        $this->classSubject($class->academicYear);

        $this->actingAs($teacher)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('variant', 'teacher')->has('classes', 1)->where('classes.0.id', $class->id));
    }

    public function test_student_receives_all_current_class_memberships(): void
    {
        [$class] = $this->classSubject();
        $student = $this->user('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $student]);
        Enrollment::factory()->create(['student_profile_id' => $profile, 'school_class_id' => $class, 'academic_year_id' => $class->academic_year_id, 'current_slot' => 1]);
        [$secondClass] = $this->classSubject($class->academicYear);
        Enrollment::factory()->create(['student_profile_id' => $profile, 'school_class_id' => $secondClass, 'academic_year_id' => $secondClass->academic_year_id, 'current_slot' => 1]);

        $this->actingAs($student)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('variant', 'student')->has('classes', 2)->where('metrics.0.label', 'My classes')->where('metrics.0.value', 2)->where('assignments', []));
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }

    /** @return array{SchoolClass, ClassSubject} */
    private function classSubject(?AcademicYear $year = null): array
    {
        $year ??= AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => 'active']);

        return [$class, ClassSubject::factory()->create(['school_class_id' => $class])];
    }
}
