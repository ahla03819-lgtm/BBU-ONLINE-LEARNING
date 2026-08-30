<?php

namespace Tests\Feature;

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

    public function test_teacher_only_receives_current_assigned_class_data(): void
    {
        [$class, $subject] = $this->classSubject();
        $teacher = $this->user('Teacher');
        TeacherClassSubjectAssignment::factory()->create(['teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $teacher]), 'class_subject_id' => $subject, 'current_slot' => 1]);
        $this->classSubject($class->academicYear);

        $this->actingAs($teacher)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('variant', 'teacher')->has('classes', 1)->where('classes.0.id', $class->id));
    }

    public function test_student_only_receives_their_current_enrolled_class_data(): void
    {
        [$class] = $this->classSubject();
        $student = $this->user('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $student]);
        Enrollment::factory()->create(['student_profile_id' => $profile, 'school_class_id' => $class, 'academic_year_id' => $class->academic_year_id, 'current_slot' => 1]);

        $this->actingAs($student)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('variant', 'student')->has('classes', 1)->where('classes.0.id', $class->id)->where('assignments', []));
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
