<?php

namespace Tests\Feature\Phase2;

use App\Actions\People\AssignTeacherToClass;
use App\Actions\People\EnrollStudent;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_teacher_can_view_only_an_assigned_class_and_its_student(): void
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('Teacher');
        $teacher = TeacherProfile::factory()->create(['user_id' => $teacherUser]);
        $assigned = SchoolClass::factory()->create();
        $unassigned = SchoolClass::factory()->create();
        app(AssignTeacherToClass::class)->handle($teacher, $assigned, '2026-09-01');
        $student = StudentProfile::factory()->create();
        app(EnrollStudent::class)->handle($student, $assigned, '2026-09-01');

        $this->assertTrue($teacherUser->can('view', $assigned));
        $this->assertFalse($teacherUser->can('view', $unassigned));
        $this->assertTrue($teacherUser->can('view', $student));
    }

    public function test_student_can_view_only_their_own_profile(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Student');
        $own = StudentProfile::factory()->create(['user_id' => $user]);
        $other = StudentProfile::factory()->create();

        $this->assertTrue($user->can('view', $own));
        $this->assertFalse($user->can('view', $other));
    }

    public function test_student_can_view_only_their_current_class(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user]);
        $ownClass = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create();
        app(EnrollStudent::class)->handle($profile, $ownClass, '2026-09-01');

        $this->assertTrue($user->can('view', $ownClass));
        $this->assertFalse($user->can('view', $otherClass));
    }

    public function test_teacher_cannot_mutate_academic_or_people_foundation(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)->post('/subjects', ['code' => 'X', 'name' => 'Forbidden', 'is_active' => true])->assertForbidden();
        $this->actingAs($teacher)->post('/grade-levels', ['name' => 'Forbidden', 'sequence' => 1, 'is_active' => true])->assertForbidden();
    }
}
