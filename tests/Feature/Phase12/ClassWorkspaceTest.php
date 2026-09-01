<?php

namespace Tests\Feature\Phase12;

use App\Enums\AcademicYearStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClassWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_student_sees_each_current_class_workspace_without_member_counts(): void
    {
        $own = $this->activeClass();
        $second = $this->activeClass($own->academicYear);
        $unrelated = $this->activeClass($own->academicYear);
        $student = $this->user('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        Enrollment::factory()->create(['student_profile_id' => $profile->id, 'academic_year_id' => $own->academic_year_id, 'school_class_id' => $own->id, 'current_slot' => 1]);
        Enrollment::factory()->create(['student_profile_id' => $profile->id, 'academic_year_id' => $second->academic_year_id, 'school_class_id' => $second->id, 'current_slot' => 1]);

        $this->actingAs($student)->get(route('classes.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Classes/Index')
            ->has('classes', 2)
            ->where('classes', fn ($classes) => collect($classes)->contains(fn ($item) => $item['id'] === $own->id && $item['memberCount'] === null)
                && collect($classes)->contains(fn ($item) => $item['id'] === $second->id && $item['memberCount'] === null))
            ->where('canCreateClass', false));
        $this->actingAs($student)->get(route('classes.show', $second))->assertOk();
        $this->actingAs($student)->get(route('classes.show', $unrelated))->assertForbidden();
    }

    public function test_current_subject_teacher_sees_only_their_assigned_workspace(): void
    {
        [$own, $subject] = $this->activeClassWithSubject();
        $other = $this->activeClass($own->academicYear);
        $teacher = $this->user('Teacher');
        TeacherClassSubjectAssignment::factory()->create(['teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $teacher->id]), 'class_subject_id' => $subject->id, 'current_slot' => 1]);

        $this->actingAs($teacher)->get(route('classes.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Classes/Index')
            ->has('classes', 1)
            ->where('classes.0.id', $own->id));
        $this->actingAs($teacher)->get(route('classes.show', $own))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Classes/Show')
            ->where('schoolClass.id', $own->id)
            ->has('tabs', 7)
            ->where('tabs', fn ($tabs) => collect($tabs)->contains(fn ($tab) => $tab['label'] === 'Members' && $tab['href'] === route('classes.members', $own))));
        $this->actingAs($teacher)->get(route('classes.show', $other))->assertForbidden();
    }

    public function test_administrator_receives_class_member_counts_and_creation_capability(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Admin');
        $student = StudentProfile::factory()->create();
        Enrollment::factory()->create(['student_profile_id' => $student->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => 1]);

        $this->actingAs($admin)->get(route('classes.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Classes/Index')
            ->where('classes', fn ($classes) => collect($classes)->contains(fn ($item) => $item['id'] === $class->id && $item['memberCount'] === 1))
            ->where('canCreateClass', true));
    }

    public function test_student_can_view_only_their_class_members_with_safe_avatar_data_and_self_marker(): void
    {
        $class = $this->activeClass();
        $student = $this->user('Student');
        $studentProfile = StudentProfile::factory()->create(['user_id' => $student->id]);
        Enrollment::factory()->create(['student_profile_id' => $studentProfile->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => 1]);
        $classmate = $this->user('Student');
        $classmate->update(['avatar_path' => "user-avatars/{$classmate->id}/avatar.webp"]);
        Enrollment::factory()->create(['student_profile_id' => StudentProfile::factory()->create(['user_id' => $classmate->id])->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => 1]);
        $teacher = $this->user('Teacher');
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $teacher->id])->id, 'school_class_id' => $class->id, 'current_slot' => 1]);
        $outside = $this->user('Student');
        $otherClass = $this->activeClass($class->academicYear);
        Enrollment::factory()->create(['student_profile_id' => StudentProfile::factory()->create(['user_id' => $outside->id])->id, 'academic_year_id' => $otherClass->academic_year_id, 'school_class_id' => $otherClass->id, 'current_slot' => 1]);

        $this->actingAs($student)->get(route('classes.members', $class))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Classes/Members')
            ->has('members', 3)
            ->where('members.0.id', $student->id)
            ->where('members.0.isCurrentUser', true)
            ->where('members.0.role', 'Student')
            ->where('members', fn ($members) => collect($members)->pluck('id')->sort()->values()->all() === collect([$student->id, $classmate->id, $teacher->id])->sort()->values()->all())
            ->where('members', fn ($members) => str_ends_with((string) collect($members)->firstWhere('id', $classmate->id)['avatarUrl'], "/user-avatars/{$classmate->id}/avatar.webp")));
    }

    public function test_teacher_can_view_their_class_members_but_an_unrelated_user_cannot_enumerate_them(): void
    {
        $class = $this->activeClass();
        $teacher = $this->user('Teacher');
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => TeacherProfile::factory()->create(['user_id' => $teacher->id])->id, 'school_class_id' => $class->id, 'current_slot' => 1]);
        $student = $this->user('Student');
        Enrollment::factory()->create(['student_profile_id' => StudentProfile::factory()->create(['user_id' => $student->id])->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => 1]);
        $unrelated = $this->user('Student');

        $this->actingAs($teacher)->get(route('classes.members', $class))->assertOk()->assertInertia(fn (Assert $page) => $page->has('members', 2));
        $this->actingAs($unrelated)->get(route('classes.members', $class))->assertForbidden();
    }

    public function test_super_admin_can_administratively_view_members_without_impersonating_any_member(): void
    {
        $class = $this->activeClass();
        $student = $this->user('Student');
        Enrollment::factory()->create(['student_profile_id' => StudentProfile::factory()->create(['user_id' => $student->id])->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => 1]);
        $superAdmin = $this->user('Super Admin');

        $this->actingAs($superAdmin)->get(route('classes.members', $class))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('members', 1)
            ->where('members.0.id', $student->id)
            ->where('members.0.isCurrentUser', false));
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }

    private function activeClass(?AcademicYear $year = null): SchoolClass
    {
        $year ??= AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);

        return SchoolClass::factory()->create(['academic_year_id' => $year->id, 'status' => SchoolClassStatus::Active]);
    }

    /** @return array{SchoolClass, ClassSubject} */
    private function activeClassWithSubject(): array
    {
        $class = $this->activeClass();

        return [$class, ClassSubject::factory()->create(['school_class_id' => $class->id])];
    }
}
