<?php

namespace Tests\Feature;

use App\Contracts\MeetingLifecycleProvider;
use App\Enums\GuestMeetingSessionStatus;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\GuestMeetingParticipantRemoval;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\GuestMeetings\GuestMeetingInvitationService;
use App\Services\GuestMeetings\GuestParticipantRemovalService;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use App\Jobs\ReconcileGuestMeetingParticipantRemoval;
use App\Services\LiveKit\LiveKitRoomManager;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Fakes\FakeLiveKitTokenIssuer;
use Tests\Fakes\FakeMeetingLifecycleProvider;
use Tests\TestCase;

class GuestMeetingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FakeLiveKitTokenIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        config(['guest-meetings.invitation_ttl_minutes' => 60, 'guest-meetings.session_ttl_minutes' => 30]);
        $this->issuer = new FakeLiveKitTokenIssuer;
        $this->app->instance(LiveKitTokenIssuer::class, $this->issuer);
    }

    public function test_guest_leave_revokes_only_its_session_clears_its_cookie_and_is_idempotent(): void
    {
        Queue::fake();
        [, $host, , $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertOk();
        $participant = $guest->session->fresh()->participant;
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('removeParticipant')->once()->with($meeting->livekit_room_name, $participant->livekit_identity)->andReturn(MeetingProviderState::Ended);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $leave = route('guest.sessions.leave', ['guestSession' => $guest->session->public_uuid]);
        $this->guestRequest($guest)->postJson($leave)->assertOk()
            ->assertJsonPath('session.status', 'removed')
            ->assertJsonPath('redirect_url', '/login')
            ->assertCookieExpired(config('guest-meetings.cookie_name'));
        $this->guestRequest($guest)->postJson($leave)->assertOk()->assertJsonPath('session.status', 'removed');

        $this->assertDatabaseHas('guest_meeting_sessions', ['id' => $guest->session->id, 'status' => 'removed']);
        $this->assertNotNull($guest->session->fresh()->invalidated_at);
        $this->assertNull($participant->fresh()->join_reserved_until);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertNotFound();
        $this->guestRequest($guest)->get($this->roomUrl($guest))->assertNotFound();
        $removal = GuestMeetingParticipantRemoval::query()->sole();
        $this->assertSame($guest->session->id, $removal->guest_meeting_session_id);
        $this->assertSame($meeting->id, $removal->meeting_id);
        $this->assertSame($participant->id, $removal->meeting_participant_id);
        $this->assertSame($meeting->livekit_room_name, $removal->room_name);
        $this->assertSame($participant->livekit_identity, $removal->participant_identity);
        Queue::assertPushed(ReconcileGuestMeetingParticipantRemoval::class, fn ($job) => $job->removalId === $removal->id);
        (new ReconcileGuestMeetingParticipantRemoval($removal->id))->handle(app(GuestParticipantRemovalService::class));
        $this->assertNotNull($removal->fresh()->resolved_at);
    }

    public function test_guest_leave_cannot_target_another_guest_or_log_out_a_shared_authenticated_user(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $first = $this->guest($host, $meeting, admitted: true);
        $second = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($first)->postJson(route('guest.sessions.leave', ['guestSession' => $second->session->public_uuid]))->assertNotFound();
        $this->assertSame(GuestMeetingSessionStatus::Admitted, $second->session->fresh()->status);

        $this->actingAs($host)->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $first->credential)
            ->postJson(route('guest.sessions.leave', ['guestSession' => $first->session->public_uuid]))
            ->assertOk()->assertJsonPath('redirect_url', '/meetings/guest/exited');
        $this->assertAuthenticatedAs($host);
        $this->get('/dashboard')->assertOk();
    }

    public function test_host_removal_revokes_only_the_linked_guest_and_targets_its_opaque_identity(): void
    {
        Queue::fake();
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertOk();
        $participant = $guest->session->fresh()->participant;
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('removeParticipant')->once()->with($meeting->livekit_room_name, $participant->livekit_identity)->andReturn(MeetingProviderState::Ended);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $remove = route('meetings.participants.destroy', [$class, $meeting, $participant]);
        $this->actingAs($student)->deleteJson($remove)->assertForbidden();
        $this->actingAs($host)->deleteJson($remove)->assertOk();

        $this->assertNotNull($participant->fresh()->removed_at);
        $this->assertSame(GuestMeetingSessionStatus::Removed, $guest->session->fresh()->status);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertNotFound();
        $this->guestRequest($guest)->getJson(route('guest.sessions.waiting-status', ['guestSession' => $guest->session->public_uuid]))
            ->assertOk()->assertJsonPath('session.status', 'removed');
        $removal = GuestMeetingParticipantRemoval::query()->sole();
        $this->assertSame($guest->session->id, $removal->guest_meeting_session_id);
        $this->assertSame($meeting->livekit_room_name, $removal->room_name);
        $this->assertSame($participant->livekit_identity, $removal->participant_identity);
        Queue::assertPushed(ReconcileGuestMeetingParticipantRemoval::class, fn ($job) => $job->removalId === $removal->id);
        (new ReconcileGuestMeetingParticipantRemoval($removal->id))->handle(app(GuestParticipantRemovalService::class));
        $this->assertNotNull($removal->fresh()->resolved_at);
    }

    public function test_provider_removal_failure_keeps_guest_authorization_revoked(): void
    {
        Queue::fake();
        [, $host, , $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertOk();
        $participant = $guest->session->fresh()->participant;
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('removeParticipant')->once()->with($meeting->livekit_room_name, $participant->livekit_identity)->andReturn(MeetingProviderState::Unknown);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->guestRequest($guest)->postJson(route('guest.sessions.leave', ['guestSession' => $guest->session->public_uuid]))->assertOk();
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertNotFound();
        $removal = GuestMeetingParticipantRemoval::query()->sole();
        Queue::assertPushed(ReconcileGuestMeetingParticipantRemoval::class, fn ($job) => $job->removalId === $removal->id);
        (new ReconcileGuestMeetingParticipantRemoval($removal->id))->handle(app(GuestParticipantRemovalService::class));
        $this->assertSame(1, $removal->fresh()->attempt_count);
        $this->assertNull($removal->fresh()->resolved_at);
        $this->assertNotNull($removal->fresh()->next_attempt_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'guest-meeting.provider-removal-pending']);
    }

    public function test_meeting_end_invalidates_pending_and_admitted_guests_before_room_deletion(): void
    {
        [$class, $host, , $meeting] = $this->meetingContext();
        $pending = $this->guest($host, $meeting);
        $admitted = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($admitted)->postJson($this->tokenUrl($admitted))->assertOk();
        $participant = $admitted->session->fresh()->participant;
        $provider = new FakeMeetingLifecycleProvider;
        $this->app->instance(MeetingLifecycleProvider::class, $provider);

        $this->actingAs($host)->postJson(route('meetings.end', [$class, $meeting]))->assertOk()->assertJsonPath('meeting.status', 'ended');
        foreach ([$pending, $admitted] as $guest) {
            $this->assertSame(GuestMeetingSessionStatus::Expired, $guest->session->fresh()->status);
            $this->assertNotNull($guest->session->fresh()->invalidated_at);
            $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertNotFound();
        }
        $this->assertNull($participant->fresh()->join_reserved_until);
        $this->assertSame(1, $provider->endCalls);
        $this->guestRequest($admitted)->getJson(route('guest.sessions.waiting-status', ['guestSession' => $admitted->session->public_uuid]))
            ->assertOk()->assertJsonPath('session.status', 'meeting_ended');
    }

    public function test_provider_failure_is_durable_and_a_later_worker_resolves_the_exact_guest(): void
    {
        Queue::fake();
        [, $host, , $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertOk();
        $participant = $guest->session->fresh()->participant;
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('removeParticipant')->twice()->with($meeting->livekit_room_name, $participant->livekit_identity)
            ->andReturn(MeetingProviderState::Unknown, MeetingProviderState::Ended);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->guestRequest($guest)->postJson(route('guest.sessions.leave', ['guestSession' => $guest->session->public_uuid]))->assertOk();
        $removal = GuestMeetingParticipantRemoval::query()->sole();
        Queue::assertPushed(ReconcileGuestMeetingParticipantRemoval::class, fn ($job) => $job->removalId === $removal->id);
        (new ReconcileGuestMeetingParticipantRemoval($removal->id))->handle(app(GuestParticipantRemovalService::class));
        $this->assertSame(1, $removal->fresh()->attempt_count);
        $this->assertNull($removal->fresh()->resolved_at);
        $removal->update(['next_attempt_at' => now()->subSecond()]);
        // A worker restart recovers solely from the persisted reconciliation row.
        app(GuestParticipantRemovalService::class)->reconcileDue(10);
        $this->assertNotNull($removal->fresh()->resolved_at);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertNotFound();
    }

    public function test_duplicate_jobs_are_idempotent_and_bounded_failure_remains_fail_closed(): void
    {
        Queue::fake();
        config(['guest-meetings.removal_max_attempts' => 2, 'guest-meetings.removal_backoff_seconds' => [1]]);
        [, $host, , $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertOk();
        $participant = $guest->session->fresh()->participant;
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('removeParticipant')->twice()->with($meeting->livekit_room_name, $participant->livekit_identity)->andReturn(MeetingProviderState::Unknown);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->guestRequest($guest)->postJson(route('guest.sessions.leave', ['guestSession' => $guest->session->public_uuid]))->assertOk();
        $removal = GuestMeetingParticipantRemoval::query()->sole();
        $job = new ReconcileGuestMeetingParticipantRemoval($removal->id);
        $job->handle(app(GuestParticipantRemovalService::class));
        $removal->update(['next_attempt_at' => now()->subSecond()]);
        $job->handle(app(GuestParticipantRemovalService::class));
        $job->handle(app(GuestParticipantRemovalService::class));

        $this->assertSame(2, $removal->fresh()->attempt_count);
        $this->assertNotNull($removal->fresh()->permanently_failed_at);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'guest-meeting.provider-removal-permanently-failed']);
    }

    public function test_expiry_command_releases_guest_capacity_without_browser_polling_and_leaves_users_alone(): void
    {
        Queue::fake();
        [, $host, $student, $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertOk();
        $participant = $guest->session->fresh()->participant;
        $studentParticipant = \App\Models\MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $student->id, 'join_reserved_until' => now()->addMinute()]);
        $guest->session->update(['expires_at' => now()->subSecond()]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('removeParticipant')->once()->with($meeting->livekit_room_name, $participant->livekit_identity)->andReturn(MeetingProviderState::Ended);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->artisan('guest-meetings:reconcile')->expectsOutputToContain('Expired 1 guest session(s)')->assertSuccessful();
        $this->assertSame(GuestMeetingSessionStatus::Expired, $guest->session->fresh()->status);
        $this->assertNull($participant->fresh()->join_reserved_until);
        $this->assertNotNull($studentParticipant->fresh()->join_reserved_until);
        $this->assertDatabaseHas('guest_meeting_participant_removals', ['meeting_participant_id' => $participant->id]);
        $this->assertNotNull(GuestMeetingParticipantRemoval::query()->sole()->resolved_at);
        Queue::assertPushed(ReconcileGuestMeetingParticipantRemoval::class);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertNotFound();
    }

    public function test_reconciliation_rejects_identity_drift_and_is_scheduled_without_overlap(): void
    {
        Queue::fake();
        [, $host, , $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertOk();
        $this->guestRequest($guest)->postJson(route('guest.sessions.leave', ['guestSession' => $guest->session->public_uuid]))->assertOk();
        $removal = GuestMeetingParticipantRemoval::query()->sole();
        $removal->update(['participant_identity' => (string) str()->uuid()]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldNotReceive('removeParticipant');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        (new ReconcileGuestMeetingParticipantRemoval($removal->id))->handle(app(GuestParticipantRemovalService::class));
        $this->assertNotNull($removal->fresh()->permanently_failed_at);
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'guest-meetings:reconcile'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->sole()->expression);
        $this->assertTrue($events->sole()->withoutOverlapping);
    }

    private function guest(User $host, Meeting $meeting, bool $admitted = false): object
    {
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $issued = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest '.str()->random(8));
        if ($admitted) {
            app(GuestMeetingSessionService::class)->decide($host, $issued->session, GuestMeetingSessionStatus::Admitted);
        }

        return (object) ['session' => $issued->session->fresh(), 'credential' => $issued->credential];
    }

    private function guestRequest(object $guest): static
    {
        return $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $guest->credential);
    }

    private function tokenUrl(object $guest): string
    {
        return route('guest.sessions.token', ['guestSession' => $guest->session->public_uuid]);
    }

    private function roomUrl(object $guest): string
    {
        return route('guest.sessions.room', ['guestSession' => $guest->session->public_uuid]);
    }

    private function meetingContext(): array
    {
        $class = SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active]);
        $host = User::factory()->create();
        $host->assignRole('Teacher');
        $teacher = TeacherProfile::factory()->create(['user_id' => $host->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $teacher->id, 'school_class_id' => $class->id, 'current_slot' => 1]);
        $student = User::factory()->create();
        $student->assignRole('Student');
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Active]);

        return [$class, $host, $student, $meeting];
    }
}
