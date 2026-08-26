<?php

namespace Tests\Feature\Phase5;

use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MeetingNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_authorized_workspace_users_receive_meeting_navigation(): void
    {
        $class = $this->activeClass();

        foreach ([$this->roleUser('Super Admin'), $this->roleUser('Admin'), $this->teacher($class), $this->student($class)] as $user) {
            $this->actingAs($user)->get(route('collaboration.classes.show', $class))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Collaboration/Workspace')
                    ->where('schoolClass.id', $class->id)
                    ->where('canViewMeetings', true));
        }
    }

    public function test_meeting_navigation_is_hidden_without_permission_and_historical_students_cannot_open_workspace(): void
    {
        $class = $this->activeClass();
        $current = $this->student($class);
        Role::findByName('Student')->revokePermissionTo('meetings.view');

        $this->actingAs($current)->get(route('collaboration.classes.show', $class))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canViewMeetings', false));

        $historical = $this->student($class, false);
        $this->actingAs($historical)->get(route('collaboration.classes.show', $class))->assertForbidden();
        $this->actingAs($historical)->get(route('meetings.index', $class))->assertForbidden();
    }

    public function test_workspace_meeting_link_uses_the_current_class_identifier(): void
    {
        $component = file_get_contents(resource_path('js/Pages/Collaboration/Workspace.jsx'));

        $this->assertStringContainsString('canViewMeetings&&<Link', $component);
        $this->assertStringContainsString('href={`/school-classes/${schoolClass.id}/meetings`}', $component);
        $this->assertStringNotContainsString('/school-classes/33/meetings', $component);
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create([
            'academic_year_id' => AcademicYear::factory()->active(),
            'status' => SchoolClassStatus::Active,
        ]);
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function teacher(SchoolClass $class): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id]);

        return $user;
    }

    private function student(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
            'current_slot' => $current ? 1 : null,
            'ended_on' => $current ? null : now(),
        ]);

        return $user;
    }
}
