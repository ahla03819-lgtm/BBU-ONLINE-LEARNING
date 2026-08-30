<?php

namespace Tests\Feature\Phase11;

use App\Enums\AcademicYearStatus;
use App\Enums\ReportingPeriodStatus;
use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\ReportingPeriod;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportingPeriodFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_create_a_configurable_hierarchical_reporting_period(): void
    {
        $admin = $this->user('Admin');
        $year = $this->year();
        $this->actingAs($admin)->post('/reporting-periods', ['academic_year_id' => $year->id, 'name' => 'Cycle A', 'code' => 'cycle-a', 'sequence' => 1, 'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString()])->assertSessionHasNoErrors();
        $parent = ReportingPeriod::firstOrFail();
        $this->actingAs($admin)->post('/reporting-periods', ['academic_year_id' => $year->id, 'parent_id' => $parent->id, 'name' => 'Checkpoint', 'code' => 'cycle-a-checkpoint', 'sequence' => 1, 'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString()])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('reporting_periods', ['parent_id' => $parent->id, 'code' => 'cycle-a-checkpoint', 'status' => 'draft']);
    }

    public function test_cross_year_parent_and_duplicate_code_are_rejected(): void
    {
        $admin = $this->user('Admin');
        $first = $this->year();
        $second = AcademicYear::factory()->create();
        $parent = ReportingPeriod::factory()->create(['academic_year_id' => $first, 'starts_on' => $first->starts_on, 'ends_on' => $first->ends_on]);
        $payload = ['academic_year_id' => $second->id, 'parent_id' => $parent->id, 'name' => 'Other', 'code' => 'other', 'sequence' => 1, 'starts_on' => $second->starts_on->toDateString(), 'ends_on' => $second->ends_on->toDateString()];
        $this->actingAs($admin)->post('/reporting-periods', $payload)->assertSessionHasErrors('parent_id');
        ReportingPeriod::factory()->create(['academic_year_id' => $first, 'code' => 'unique-within-year', 'starts_on' => $first->starts_on, 'ends_on' => $first->ends_on]);
        $this->actingAs($admin)->post('/reporting-periods', ['academic_year_id' => $first->id, 'name' => 'Duplicate', 'code' => 'unique-within-year', 'sequence' => 2, 'starts_on' => $first->starts_on->toDateString(), 'ends_on' => $first->ends_on->toDateString()])->assertSessionHasErrors('code');
    }

    public function test_lifecycle_only_allows_draft_to_open_to_closed(): void
    {
        $admin = $this->user('Admin');
        $period = ReportingPeriod::factory()->create(['academic_year_id' => $this->year()]);
        $this->actingAs($admin)->patch("/reporting-periods/{$period->id}/transition", ['status' => 'open'])->assertSessionHasNoErrors();
        $this->assertSame(ReportingPeriodStatus::Open, $period->fresh()->status);
        $this->actingAs($admin)->patch("/reporting-periods/{$period->id}/transition", ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch("/reporting-periods/{$period->id}/transition", ['status' => 'open'])->assertSessionHasErrors('status');
    }

    public function test_teacher_results_access_is_limited_to_current_subject_assignment(): void
    {
        [$class, $subject] = $this->classSubject();
        $period = ReportingPeriod::factory()->create(['academic_year_id' => $class->academic_year_id]);
        $teacher = $this->teacher($subject);
        $this->actingAs($teacher)->get("/results/{$period->id}/school-classes/{$class->id}/class-subjects/{$subject->id}")->assertInertia(fn (Assert $page) => $page->component('Results/Show')->where('students', []));
        [, $otherSubject] = $this->classSubject($class->academicYear);
        $this->actingAs($teacher)->get("/results/{$period->id}/school-classes/{$otherSubject->school_class_id}/class-subjects/{$otherSubject->id}")->assertNotFound();
    }

    public function test_student_can_only_view_their_own_empty_results_context(): void
    {
        [$class] = $this->classSubject();
        $student = $this->student($class);
        $period = ReportingPeriod::factory()->create(['academic_year_id' => $class->academic_year_id]);
        $this->actingAs($student)->get('/my-results')->assertInertia(fn (Assert $page) => $page->component('Results/MyResults')->where('results', []));
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id]);
        $this->actingAs($student)->get("/my-results/reporting-periods/{$period->id}/school-classes/{$otherClass->id}")->assertNotFound();
    }

    public function test_student_cannot_manage_reporting_periods_and_assignment_link_is_optional(): void
    {
        [$class, $subject] = $this->classSubject();
        $student = $this->student($class);
        $this->actingAs($student)->post('/reporting-periods', [])->assertForbidden();
        $assignment = Assignment::factory()->create(['class_subject_id' => $subject]);
        $this->assertNull($assignment->reporting_period_id);
    }

    private function year(): AcademicYear
    {
        return AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    }

    /** @return array{SchoolClass, ClassSubject} */
    private function classSubject(?AcademicYear $year = null): array
    {
        $class = SchoolClass::factory()->create(['academic_year_id' => $year ?? $this->year(), 'status' => 'active']);

        return [$class, ClassSubject::factory()->create(['school_class_id' => $class])];
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }

    private function teacher(ClassSubject $subject): User
    {
        $user = $this->user('Teacher');
        TeacherClassSubjectAssignment::factory()->create(['teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $user]), 'class_subject_id' => $subject, 'starts_on' => now()->subDay(), 'current_slot' => 1]);

        return $user;
    }

    private function student(SchoolClass $class): User
    {
        $user = $this->user('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user]);
        Enrollment::factory()->create(['student_profile_id' => $profile, 'school_class_id' => $class, 'academic_year_id' => $class->academic_year_id, 'current_slot' => 1]);

        return $user;
    }
}
