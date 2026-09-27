<?php

namespace Tests\Feature\Phase6;

use App\Contracts\MeetingLifecycleProvider;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeMeetingLifecycleProvider;
use Tests\TestCase;

class MeetingExperienceReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private FakeMeetingLifecycleProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->provider = new FakeMeetingLifecycleProvider;
        $this->app->instance(MeetingLifecycleProvider::class, $this->provider);
    }

    public function test_authorized_host_and_administrator_can_reconcile_stale_transitions(): void
    {
        $class = $this->activeClass();
        $host = $this->teacher($class);
        $starting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Starting, 'start_attempt_uuid' => (string) Str::uuid(), 'lifecycle_version' => 4]);
        $this->provider->inspectState = MeetingProviderState::Active;

        $this->actingAs($host)->post(route('meetings.reconcile', [$class, $starting]), ['lifecycle_version' => 4])->assertRedirect();
        $this->assertSame(MeetingStatus::Active, $starting->fresh()->status);
        $this->assertSame(5, $starting->fresh()->lifecycle_version);

        $ending = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Ending, 'lifecycle_version' => 8]);
        $this->provider->inspectState = MeetingProviderState::Ended;
        $admin = $this->roleUser('Admin');
        $this->actingAs($admin)->post(route('meetings.reconcile', [$class, $ending]), ['lifecycle_version' => 8])->assertRedirect();
        $this->assertSame(MeetingStatus::Ended, $ending->fresh()->status);
    }

    public function test_reconciliation_denies_students_unrelated_teachers_and_stale_versions(): void
    {
        $class = $this->activeClass();
        $host = $this->teacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Starting, 'start_attempt_uuid' => (string) Str::uuid(), 'lifecycle_version' => 3]);
        $this->provider->inspectState = MeetingProviderState::Active;

        $this->actingAs($this->student($class))->post(route('meetings.reconcile', [$class, $meeting]), ['lifecycle_version' => 3])->assertForbidden();
        $this->actingAs($this->roleUser('Teacher'))->post(route('meetings.reconcile', [$class, $meeting]), ['lifecycle_version' => 3])->assertForbidden();
        $this->actingAs($host)->post(route('meetings.reconcile', [$class, $meeting]), ['lifecycle_version' => 2])->assertSessionHasErrors('meeting');
        $this->assertSame(MeetingStatus::Starting, $meeting->fresh()->status);
        $this->assertSame(3, $meeting->fresh()->lifecycle_version);
    }

    public function test_workspace_features_active_meeting_for_current_relationships_only(): void
    {
        $class = $this->activeClass();
        $host = $this->teacher($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);

        foreach ([$this->roleUser('Super Admin'), $this->roleUser('Admin'), $host, $this->student($class)] as $user) {
            $this->actingAs($user)->get(route('collaboration.classes.show', $class))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('featuredMeeting.uuid', $meeting->uuid)
                ->where('featuredMeeting.status', 'active')
                ->where('featuredMeeting.can_join', true)
                ->missing('featuredMeeting.livekit_room_name')
                ->missing('featuredMeeting.livekit_identity'));
        }

        $historical = $this->student($class, false);
        $this->actingAs($historical)->get(route('collaboration.classes.show', $class))->assertForbidden();
    }

    public function test_scheduled_workspace_actions_respect_role_and_host_authorization(): void
    {
        $class = $this->activeClass();
        $host = $this->teacher($class);
        Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);

        $this->actingAs($host)->get(route('collaboration.classes.show', $class))->assertInertia(fn (Assert $page) => $page
            ->where('featuredMeeting.status', 'scheduled')
            ->where('featuredMeeting.can_start', true)
            ->where('featuredMeeting.can_join', false));
        foreach ([$this->roleUser('Admin'), $this->student($class)] as $user) {
            $this->actingAs($user)->get(route('collaboration.classes.show', $class))->assertInertia(fn (Assert $page) => $page
                ->where('featuredMeeting.status', 'scheduled')
                ->where('featuredMeeting.can_start', false)
                ->where('featuredMeeting.can_join', false));
        }
        $this->actingAs($this->roleUser('Super Admin'))->get(route('collaboration.classes.show', $class))->assertInertia(fn (Assert $page) => $page
            ->where('featuredMeeting.can_start', true)
            ->where('featuredMeeting.can_join', false));
    }

    public function test_students_never_receive_participant_removal_controls(): void
    {
        $class = $this->activeClass();
        $host = $this->teacher($class);
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);

        $this->actingAs($student)->get(route('meetings.lobby', [$class, $meeting]))->assertInertia(fn (Assert $page) => $page->where('meeting.can_manage_participants', false));
        $this->actingAs($host)->get(route('meetings.lobby', [$class, $meeting]))->assertInertia(fn (Assert $page) => $page->where('meeting.can_manage_participants', true));
    }

    public function test_meeting_pages_expose_safe_actions_and_grouped_ui_without_provider_identifiers(): void
    {
        $class = $this->activeClass();
        $host = $this->teacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Starting, 'start_attempt_uuid' => (string) Str::uuid()]);

        $this->actingAs($host)->get(route('meetings.show', [$class, $meeting]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('meeting.can_reconcile', true)
            ->missing('meeting.livekit_room_name')
            ->missing('meeting.start_attempt_uuid')
            ->missing('meeting.last_provider_error'));

        $index = file_get_contents(resource_path('js/Pages/Meetings/Index.jsx'));
        $room = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));
        $controls = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingControlCenter.jsx'));
        $provider = file_get_contents(resource_path('js/Providers/PersistentMeetingProvider.jsx'));
        $stage = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingStage.jsx'));
        $show = file_get_contents(resource_path('js/Pages/Meetings/Show.jsx'));
        foreach (['Live now', 'Scheduled', 'Transitioning', 'History', 'No meetings yet'] as $label) {
            $this->assertStringContainsString($label, $index);
        }
        foreach (['Connecting…', 'Connected', 'Reconnecting…', 'Disconnected', 'Camera off', 'Muted', 'participant.name'] as $label) {
            $this->assertStringContainsString($label, $room.$controls.$stage);
        }
        $this->assertStringContainsString("onDisconnected={() => setConnectionError('The meeting connection was interrupted. Refresh to reconnect.')}", $room);
        $this->assertStringNotContainsString('if (connected.current) onLeave()', $room);
        $this->assertStringContainsString('await stopAll();', $controls);
        $this->assertStringContainsString('await onLeave(() => room.disconnect());', $controls);
        $this->assertStringContainsString('await current.onLeave?.();', $provider);
        $this->assertStringContainsString('disabled={ending}', $show);
        $this->assertStringContainsString('Retry reconciliation', $show);
        $this->assertStringNotContainsString('livekit_identity', $room.$controls);
        $this->assertStringNotContainsString('livekit_room_name', $room.$controls);
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
        Enrollment::factory()->create(['student_profile_id' => $profile->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => $current ? 1 : null, 'ended_on' => $current ? null : now()]);

        return $user;
    }
}
