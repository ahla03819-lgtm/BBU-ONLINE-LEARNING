<?php

namespace Tests\Feature\Phase7;

use App\Enums\AccountStatus;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingJoinRequest;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Policies\MeetingPolicy;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Fakes\FakeLiveKitTokenIssuer;
use Tests\TestCase;

class ScreenSharingAndHostControlsTest extends TestCase
{
    use RefreshDatabase;

    private FakeLiveKitTokenIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->issuer = new FakeLiveKitTokenIssuer;
        $this->app->instance(LiveKitTokenIssuer::class, $this->issuer);
        config(['livekit.url' => 'wss://public.example.test']);
    }

    public function test_screen_share_permission_matrix_is_relationship_and_lifecycle_scoped(): void
    {
        $class = $this->activeClass();
        $teacher = $this->teacher($class);
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);
        $policy = app(MeetingPolicy::class);

        $this->assertTrue($policy->screenShare($this->roleUser('Super Admin'), $meeting));
        $this->assertTrue($policy->screenShare($this->roleUser('Admin'), $meeting));
        $this->assertTrue($policy->screenShare($teacher, $meeting));
        $this->assertFalse($policy->screenShare($student, $meeting));
        $this->assertFalse($policy->screenShare($this->teacher($class, false), $meeting));
        $this->assertFalse($policy->screenShare($this->student($class, false), $meeting));

        $teacher->update(['status' => AccountStatus::Inactive]);
        $this->assertFalse($policy->screenShare($teacher, $meeting));
        $teacher->update(['status' => AccountStatus::Active]);
        $teacher->forceFill(['email_verified_at' => null])->save();
        $this->assertFalse($policy->screenShare($teacher, $meeting));
        $teacher->forceFill(['email_verified_at' => now()])->save();
        $meeting->update(['status' => MeetingStatus::Ending]);
        $this->assertFalse($policy->screenShare($teacher, $meeting));
    }

    public function test_token_grants_screen_sources_only_to_authorized_users(): void
    {
        $class = $this->activeClass();
        $teacher = $this->teacher($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);

        $this->actingAs($teacher)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
        $this->assertSame(['camera', 'microphone', 'screen_share', 'screen_share_audio'], $this->issuer->publishSources);

        $student = $this->student($class);
        MeetingJoinRequest::factory()->admitted()->create(['meeting_id' => $meeting->id, 'requester_user_id' => $student->id]);
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
        $this->assertSame(['camera', 'microphone'], $this->issuer->publishSources);
    }

    public function test_lobby_serialization_exposes_only_safe_screen_share_capability(): void
    {
        $class = $this->activeClass();
        $teacher = $this->teacher($class);
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);

        $this->actingAs($teacher)->get(route('meetings.lobby', [$class, $meeting]))->assertInertia(fn (Assert $page) => $page
            ->where('meeting.can_screen_share', true)
            ->where('meeting.can_manage_participants', true)
            ->missing('meeting.livekit_room_name')
            ->missing('meeting.livekit_identity'));
        $this->actingAs($student)->get(route('meetings.lobby', [$class, $meeting]))->assertInertia(fn (Assert $page) => $page
            ->where('meeting.can_screen_share', false)
            ->where('meeting.can_manage_participants', false));
    }

    public function test_screen_share_and_host_control_ui_uses_authoritative_livekit_state_and_safe_feedback(): void
    {
        $component = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));

        foreach (['Track.Source.ScreenShare', 'useTrackToggle', 'isScreenShareEnabled', 'Screen shared by', 'Share screen', 'Stop sharing screen', 'setScreenShareEnabled(false)', 'Removing…', 'Participant removed.', 'Unable to remove the participant.', 'Reconnecting…'] as $contract) {
            $this->assertStringContainsString($contract, $component);
        }
        $this->assertStringContainsString("connection === 'connected' && meeting.status === 'active'", $component);
        $this->assertStringContainsString("['ending', 'ended', 'cancelled']", $component);
        $this->assertStringNotContainsString('livekit_identity', $component);
        $this->assertStringNotContainsString('livekit_room_name', $component);
        $this->assertStringNotContainsString('error.message', $component);
        $this->assertStringNotContainsString('getDisplayMedia(', $component);
    }

    public function test_rbac_upgrade_assigns_screen_share_without_broadening_students(): void
    {
        $this->assertTrue(Role::findByName('Super Admin')->hasPermissionTo('meetings.screen-share'));
        $this->assertTrue(Role::findByName('Admin')->hasPermissionTo('meetings.screen-share'));
        $this->assertTrue(Role::findByName('Teacher')->hasPermissionTo('meetings.screen-share'));
        $this->assertFalse(Role::findByName('Student')->hasPermissionTo('meetings.screen-share'));

        $this->seed(RolePermissionSeeder::class);
        $this->artisan('meetings:verify-permissions')->assertSuccessful();
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active]);
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function teacher(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id, 'current_slot' => $current ? 1 : null, 'ends_on' => $current ? null : now()]);

        return $user;
    }

    private function student(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create(['student_profile_id' => $profile->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => $current ? 1 : null, 'ended_on' => $current ? null : now()]);

        return $user;
    }
}
