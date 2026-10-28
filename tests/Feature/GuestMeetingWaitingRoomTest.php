<?php

namespace Tests\Feature;

use App\Enums\GuestMeetingSessionStatus;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\GuestMeetingSession;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\GuestMeetings\GuestMeetingInvitationService;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestMeetingWaitingRoomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These feature assertions exercise Inertia response data, not compiled
        // frontend assets. Laravel's test Vite stub keeps the isolated suite from
        // depending on a production manifest.
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        config(['guest-meetings.invitation_ttl_minutes' => 60, 'guest-meetings.session_ttl_minutes' => 30]);
    }

    public function test_public_join_contract_is_safe_and_does_not_create_a_session(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);

        $this->get(route('guest.invitations.show', ['token' => $invite->token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('GuestMeetings/Join')
                ->where('invitation.available', true)
                ->has('invitation.meeting_title')
                ->has('invitation.expires_at'))
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('guest_meeting_sessions', 0);
    }

    public function test_invalid_expired_and_revoked_invitations_are_unavailable_without_sessions(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $service = app(GuestMeetingInvitationService::class);
        $expired = $service->create($host, $meeting);
        $expired->invitation->update(['expires_at' => now()->subSecond()]);
        $this->get(route('guest.invitations.show', ['token' => $expired->token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('GuestMeetings/Join')->where('invitation.available', false));

        $revoked = $service->create($host, $meeting);
        $service->revoke($host, $revoked->invitation);
        $this->get(route('guest.invitations.show', ['token' => $revoked->token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('GuestMeetings/Join')->where('invitation.available', false));
        $this->assertDatabaseCount('guest_meeting_sessions', 0);
    }

    public function test_guest_request_normalizes_name_creates_waiting_session_and_reuses_retry_credential(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $first = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest Name');
        $second = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest Name', $first->credential);

        $this->assertSame($first->session->id, $second->session->id);
        $this->assertDatabaseCount('guest_meeting_sessions', 1);

        // A fresh browser has no route-scoped retry credential and therefore
        // remains a distinct guest even when it uses the same display name.
        $this->post(route('guest.sessions.store', ['token' => $invite->token]), ['display_name' => '  Guest   Name  '])
            ->assertRedirect();
        $this->assertDatabaseCount('guest_meeting_sessions', 2);

        // A duplicate submission from the original browser carries the retry
        // credential and must redirect to its original waiting-room session.
        $this->withCookie(config('guest-meetings.retry_cookie_name'), $first->credential)
            ->post(route('guest.sessions.store', ['token' => $invite->token]), ['display_name' => '  Guest   Name  '])
            ->assertRedirect(route('guest.sessions.waiting', ['guestSession' => $first->session->public_uuid]));
        $this->assertDatabaseCount('guest_meeting_sessions', 2);
    }

    public function test_terminal_retry_credentials_create_fresh_pending_requests_without_restoring_old_authority(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        foreach ([GuestMeetingSessionStatus::Rejected, GuestMeetingSessionStatus::Removed, GuestMeetingSessionStatus::Expired] as $status) {
            $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
            $old = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Terminal Guest');
            if ($status === GuestMeetingSessionStatus::Rejected) {
                app(GuestMeetingSessionService::class)->decide($host, $old->session, $status);
            } else {
                $old->session->update([
                    'status' => $status,
                    'invalidated_at' => now(),
                    'expires_at' => $status === GuestMeetingSessionStatus::Expired ? now()->subSecond() : $old->session->expires_at,
                ]);
            }

            $this->withCookie(config('guest-meetings.retry_cookie_name'), $old->credential)
                ->post(route('guest.sessions.store', ['token' => $invite->token]), ['display_name' => 'Terminal Guest'])
                ->assertRedirect()
                ->assertCookie(config('guest-meetings.retry_cookie_name'));

            $fresh = GuestMeetingSession::query()->where('invitation_id', $invite->invitation->id)->latest('id')->firstOrFail();
            $this->assertNotSame($old->session->public_uuid, $fresh->public_uuid);
            $this->assertSame(GuestMeetingSessionStatus::Pending, $fresh->status);
            $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $old->credential)
                ->postJson(route('guest.sessions.token', ['guestSession' => $old->session->public_uuid]))->assertNotFound();
        }
    }

    public function test_forged_retry_credential_cannot_reuse_a_guest_session(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $old = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');

        $this->withCookie(config('guest-meetings.retry_cookie_name'), str_repeat('f', 64))
            ->post(route('guest.sessions.store', ['token' => $invite->token]), ['display_name' => 'Guest One'])
            ->assertRedirect()
            ->assertCookie(config('guest-meetings.retry_cookie_name'));

        $this->assertDatabaseCount('guest_meeting_sessions', 2);
        $this->assertNotSame($old->session->public_uuid, GuestMeetingSession::query()->latest('id')->value('public_uuid'));
    }

    public function test_revoked_or_ended_invitation_never_creates_a_fresh_retry_session(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $service = app(GuestMeetingInvitationService::class);
        $revoked = $service->create($host, $meeting);
        $old = app(GuestMeetingSessionService::class)->create($revoked->invitation, 'Guest One');
        $service->revoke($host, $revoked->invitation);

        $this->withCookie(config('guest-meetings.retry_cookie_name'), $old->credential)
            ->postJson(route('guest.sessions.store', ['token' => $revoked->token]), ['display_name' => 'Guest One'])->assertNotFound();
        $this->assertDatabaseCount('guest_meeting_sessions', 1);

        $ended = $service->create($host, $meeting);
        $meeting->update(['status' => MeetingStatus::Ended]);
        $this->postJson(route('guest.sessions.store', ['token' => $ended->token]), ['display_name' => 'Guest Two'])->assertNotFound();
        $this->assertDatabaseCount('guest_meeting_sessions', 1);
    }

    public function test_admitted_retry_continues_the_existing_guest_without_duplicate_and_survives_invitation_revocation(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $admitted = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');
        app(GuestMeetingSessionService::class)->decide($host, $admitted->session, GuestMeetingSessionStatus::Admitted);

        $continued = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One', $admitted->credential);
        $this->assertSame($admitted->session->id, $continued->session->id);
        $this->assertDatabaseCount('guest_meeting_sessions', 1);
        app(GuestMeetingInvitationService::class)->revoke($host, $invite->invitation);
        $this->assertTrue(app(GuestMeetingSessionService::class)->isUsable($admitted->session->fresh()));
    }

    public function test_valid_guest_status_pollers_on_one_ip_have_independent_bounded_buckets(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $first = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');
        $second = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest Two');
        $ip = '198.51.100.81';

        foreach (range(1, 12) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->withCredentials()
                ->withCookie(config('guest-meetings.cookie_name'), $first->credential)
                ->getJson(route('guest.sessions.waiting-status', ['guestSession' => $first->session->public_uuid]))->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->withCredentials()
            ->withCookie(config('guest-meetings.cookie_name'), $first->credential)
            ->getJson(route('guest.sessions.waiting-status', ['guestSession' => $first->session->public_uuid]))->assertTooManyRequests();

        foreach (range(1, 12) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->withCredentials()
                ->withCookie(config('guest-meetings.cookie_name'), $second->credential)
                ->getJson(route('guest.sessions.waiting-status', ['guestSession' => $second->session->public_uuid]))->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->withCredentials()
            ->withCookie(config('guest-meetings.cookie_name'), $second->credential)
            ->getJson(route('guest.sessions.waiting-status', ['guestSession' => $second->session->public_uuid]))->assertTooManyRequests();
    }

    public function test_invalid_status_floods_and_public_invitation_reads_remain_rate_limited(): void
    {
        $invalidIp = '198.51.100.82';
        $invalid = route('guest.sessions.waiting-status', ['guestSession' => '11111111-1111-4111-8111-111111111111']);
        foreach (range(1, 60) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $invalidIp])->getJson($invalid)->assertNotFound();
        }
        $this->withServerVariables(['REMOTE_ADDR' => $invalidIp])->getJson($invalid)->assertTooManyRequests();

        [, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $invitationIp = '198.51.100.83';
        foreach (range(1, 20) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $invitationIp])->get(route('guest.invitations.show', ['token' => $invite->token]))->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => $invitationIp])->get(route('guest.invitations.show', ['token' => $invite->token]))->assertTooManyRequests();
    }

    public function test_two_guests_with_the_same_display_name_have_distinct_sessions(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $first = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest');
        $second = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest');

        $this->assertNotSame($first->session->public_uuid, $second->session->public_uuid);
        $this->assertDatabaseCount('guest_meeting_sessions', 2);
    }

    public function test_guest_waiting_status_is_cookie_scoped_and_terminal_states_remain_private(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $first = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');
        $second = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest Two');
        $url = route('guest.sessions.waiting-status', ['guestSession' => $first->session->public_uuid]);

        $this->getJson($url)->assertNotFound();
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $second->credential)->getJson($url)->assertNotFound();
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $first->credential)->getJson($url)->assertOk()->assertJsonPath('session.status', 'pending');

        app(GuestMeetingSessionService::class)->decide($host, $first->session, GuestMeetingSessionStatus::Rejected);
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $first->credential)->getJson($url)->assertOk()->assertJsonPath('session.status', 'rejected');
    }

    public function test_guest_can_cancel_only_their_pending_request(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $guest = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');

        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $guest->credential)
            ->deleteJson(route('guest.sessions.destroy', ['guestSession' => $guest->session->public_uuid]))
            ->assertOk()
            ->assertJsonPath('session.status', 'removed');
        $this->assertDatabaseHas('guest_meeting_sessions', ['id' => $guest->session->id, 'status' => 'removed']);
    }

    public function test_authorized_host_lists_and_decides_only_pending_guest_requests(): void
    {
        [$class, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $guest = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');
        $index = route('meetings.guest-requests.index', [$class, $meeting]);
        $update = route('meetings.guest-requests.update', [$class, $meeting, $guest->session]);

        $this->actingAs($host)->getJson($index)->assertOk()->assertJsonPath('requests.0.participant_type', 'guest')->assertJsonPath('requests.0.display_name', 'Guest One');
        $this->actingAs($host)->patchJson($update, ['decision' => 'admitted'])->assertOk()->assertJsonPath('request.status', 'admitted');
        $this->assertDatabaseHas('guest_meeting_sessions', ['id' => $guest->session->id, 'status' => 'admitted', 'decided_by' => $host->id]);
        $this->actingAs($host)->patchJson($update, ['decision' => 'rejected'])->assertUnprocessable();
    }

    public function test_students_unrelated_teachers_and_cross_meeting_ids_cannot_manage_guest_requests(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $guest = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');
        $unrelated = User::factory()->create();
        $unrelated->assignRole('Teacher');
        $otherMeeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Active]);
        $index = route('meetings.guest-requests.index', [$class, $meeting]);
        $update = route('meetings.guest-requests.update', [$class, $meeting, $guest->session]);

        $this->actingAs($student)->getJson($index)->assertForbidden();
        $this->actingAs($student)->patchJson($update, ['decision' => 'admitted'])->assertForbidden();
        $this->actingAs($unrelated)->getJson($index)->assertForbidden();
        $this->actingAs($host)->patchJson(route('meetings.guest-requests.update', [$class, $otherMeeting, $guest->session]), ['decision' => 'admitted'])->assertNotFound();
    }

    public function test_a_truly_unauthenticated_guest_cookie_cannot_decide_a_guest_request(): void
    {
        [$class, $host, , $meeting] = $this->meetingContext();
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $guest = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');
        $update = route('meetings.guest-requests.update', [$class, $meeting, $guest->session]);

        // This test has no preceding actingAs call: the guest cookie alone is
        // not Laravel authentication and the auth middleware must return 401.
        $this->withCredentials()
            ->withCookie(config('guest-meetings.cookie_name'), $guest->credential)
            ->patchJson($update, ['decision' => 'admitted'])
            ->assertUnauthorized();
    }

    public function test_terminal_meeting_expiry_and_revocation_block_new_decisions_but_revocation_does_not_remove_admitted_guest(): void
    {
        [$class, $host, , $meeting] = $this->meetingContext();
        $service = app(GuestMeetingInvitationService::class);
        $invite = $service->create($host, $meeting);
        $guest = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');
        $update = route('meetings.guest-requests.update', [$class, $meeting, $guest->session]);

        $meeting->update(['status' => MeetingStatus::Ended]);
        $this->actingAs($host)->patchJson($update, ['decision' => 'admitted'])->assertUnprocessable();
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $guest->credential)->getJson(route('guest.sessions.waiting-status', ['guestSession' => $guest->session->public_uuid]))->assertOk()->assertJsonPath('session.status', 'meeting_ended');

        $meeting->update(['status' => MeetingStatus::Active]);
        $admittedInvite = $service->create($host, $meeting);
        $admitted = app(GuestMeetingSessionService::class)->create($admittedInvite->invitation, 'Guest Two');
        app(GuestMeetingSessionService::class)->decide($host, $admitted->session, GuestMeetingSessionStatus::Admitted);
        $service->revoke($host, $admittedInvite->invitation);
        $this->assertTrue(app(GuestMeetingSessionService::class)->isUsable($admitted->session->fresh(['invitation.meeting'])));
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $admitted->credential)->getJson(route('guest.sessions.waiting-status', ['guestSession' => $admitted->session->public_uuid]))->assertOk()->assertJsonPath('session.status', 'admitted');
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
