<?php

namespace Tests\Feature\Phase12;

use App\Enums\AcademicYearStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CollaborationAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Administrator visibility must come from an allow list.
 *
 * The predicate used to read "administrator unless Teacher or Student", which made a
 * role a viewer legitimately holds a reason to lose global visibility. A Super Admin
 * who is also a Teacher fell into the teacher branch, and a teacher profile with no
 * assignments resolved to zero classes and zero channels.
 *
 * Roles are additive in this application, so a viewer is scoped by the narrowest role
 * they hold: holding an extra role must never remove access.
 */
class AdministratorClassVisibilityPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    /** @var array<int, SchoolClass> */
    private array $classes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->year = AcademicYear::factory()->create([
            'status' => AcademicYearStatus::Active,
            'active_slot' => 1,
        ]);

        $this->classes = collect(range(1, 3))
            ->map(fn () => SchoolClass::factory()->create([
                'academic_year_id' => $this->year->id,
                'status' => SchoolClassStatus::Active,
            ]))
            ->all();
    }

    private function userWithRoles(string ...$roles): User
    {
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    private function access(): CollaborationAccess
    {
        return app(CollaborationAccess::class);
    }

    private function classIdsFor(User $user): array
    {
        return $this->access()->classesFor($user)->pluck('id')->all();
    }

    public function test_super_admin_who_is_also_a_teacher_still_sees_every_class(): void
    {
        $user = $this->userWithRoles('Super Admin', 'Teacher');

        $this->assertTrue($this->access()->isAdministrator($user));
        $this->assertCount(count($this->classes), $this->classIdsFor($user));
    }

    public function test_admin_who_is_also_a_teacher_keeps_administrator_visibility(): void
    {
        $user = $this->userWithRoles('Admin', 'Teacher');

        $this->assertTrue($this->access()->isAdministrator($user));
        $this->assertCount(count($this->classes), $this->classIdsFor($user));
    }

    public function test_a_super_admin_with_no_assignments_is_not_emptied_by_the_teacher_branch(): void
    {
        $user = $this->userWithRoles('Super Admin', 'Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);

        // The exact shape that produced zero classes: a teacher profile, and no
        // assignment rows at all.
        $this->assertSame(0, $profile->classAssignments()->count());
        $this->assertCount(count($this->classes), $this->classIdsFor($user));
    }

    public function test_super_admin_alone_sees_every_class(): void
    {
        $user = $this->userWithRoles('Super Admin');

        $this->assertTrue($this->access()->isAdministrator($user));
        $this->assertCount(count($this->classes), $this->classIdsFor($user));
    }

    public function test_adding_or_removing_teacher_does_not_change_an_administrators_visibility(): void
    {
        $user = $this->userWithRoles('Super Admin');
        TeacherProfile::factory()->create(['user_id' => $user->id]);
        $before = $this->classIdsFor($user);

        $user->assignRole('Teacher');
        $withTeacher = $this->classIdsFor($user->fresh());

        $user->fresh()->removeRole('Teacher');
        $withoutTeacher = $this->classIdsFor($user->fresh());

        $this->assertSame($before, $withTeacher);
        $this->assertSame($before, $withoutTeacher);
    }

    public function test_a_teacher_without_assignments_is_not_an_administrator(): void
    {
        $user = $this->userWithRoles('Teacher');
        TeacherProfile::factory()->create(['user_id' => $user->id]);

        $this->assertFalse($this->access()->isAdministrator($user));
        $this->assertSame([], $this->classIdsFor($user));
    }

    public function test_a_teacher_sees_only_the_classes_assigned_to_them(): void
    {
        $user = $this->userWithRoles('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'school_class_id' => $this->classes[0]->id,
        ]);

        $this->assertFalse($this->access()->isAdministrator($user));
        $this->assertSame([$this->classes[0]->id], $this->classIdsFor($user));
    }

    public function test_a_teacher_who_is_also_a_student_keeps_the_narrow_scope(): void
    {
        $user = $this->userWithRoles('Teacher', 'Student');
        TeacherProfile::factory()->create(['user_id' => $user->id]);

        $this->assertFalse($this->access()->isAdministrator($user));
        $this->assertSame([], $this->classIdsFor($user));
    }

    public function test_channels_follow_the_same_administrator_precedence(): void
    {
        $administrator = $this->userWithRoles('Super Admin', 'Teacher');
        $teacher = $this->userWithRoles('Teacher');
        TeacherProfile::factory()->create(['user_id' => $teacher->id]);

        // An administrator is not narrowed by also holding Teacher.
        $this->assertTrue($this->access()->isAdministrator($administrator));
        $this->assertFalse($this->access()->isAdministrator($teacher));
    }

    public function test_the_service_agrees_with_its_sibling_services(): void
    {
        $user = $this->userWithRoles('Super Admin', 'Teacher');

        // The same viewer, the same question, asked of the access services that
        // already used an allow list. They must not disagree.
        $this->assertTrue(app(\App\Services\CourseworkAccess::class)->isAdministrator($user));
        $this->assertTrue(app(\App\Services\MeetingAccess::class)->isAdministrator($user));
        $this->assertTrue($this->access()->isAdministrator($user));
    }
}