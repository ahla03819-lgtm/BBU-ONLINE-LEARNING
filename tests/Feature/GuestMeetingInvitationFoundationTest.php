<?php

namespace Tests\Feature;

use App\Enums\GuestMeetingSessionStatus;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\GuestMeetingInvitation;
use App\Models\GuestMeetingSession;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\GuestMeetings\GuestMeetingInvitationService;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestMeetingInvitationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Guest invitation assertions cover server contracts and Inertia data;
        // they must not depend on an isolated build manifest or asset version.
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        config(['guest-meetings.invitation_ttl_minutes' => 60, 'guest-meetings.session_ttl_minutes' => 30]);
    }

    public function test_assigned_host_creates_a_hashed_bounded_invitation_without_a_guest_token(): void
    {
        [$class, $host, , $meeting] = $this->meetingContext(MeetingStatus::Scheduled);

        $response = $this->actingAs($host)->postJson(route('guest-invitations.store', [$class, $meeting]));
        $response->assertCreated()
            ->assertJsonPath('invitation.version', 1)
            ->assertJsonMissingPath('invitation.token')
            ->assertJsonMissingPath('invitation.token_hash');

        $token = $this->tokenFrom($response->json('invite_url'));
        $invitation = GuestMeetingInvitation::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isFuture());
        // Guest-D adds a protected room-token endpoint. The invitation response
        // itself must still never expose a usable room token.
        $this->assertTrue(Route::has('guest.sessions.token'));
    }

    public function test_valid_public_invitation_is_non_cacheable_and_invalid_get_creates_no_session(): void
    {
        [, $host, , $meeting] = $this->meetingContext(MeetingStatus::Scheduled);
        $issued = app(GuestMeetingInvitationService::class)->create($host, $meeting);

        $this->getJson(route('guest.invitations.show', ['token' => $issued->token]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('invitation.available', true);
        $this->getJson(route('guest.invitations.show', ['token' => str_repeat('a', 64)]))->assertNotFound();
        $this->assertDatabaseCount('guest_meeting_sessions', 0);
    }

    public function test_expired_revoked_rotated_and_terminal_meeting_invitations_are_unusable(): void
    {
        [, $host, , $meeting] = $this->meetingContext(MeetingStatus::Scheduled);
        $service = app(GuestMeetingInvitationService::class);
        $expired = $service->create($host, $meeting);
        $expired->invitation->update(['expires_at' => now()->subSecond()]);
        $this->getJson(route('guest.invitations.show', ['token' => $expired->token]))->assertNotFound();

        $revoked = $service->create($host, $meeting);
        $service->revoke($host, $revoked->invitation);
        $this->getJson(route('guest.invitations.show', ['token' => $revoked->token]))->assertNotFound();

        $original = $service->create($host, $meeting);
        $replacement = $service->rotate($host, $original->invitation);
        $this->getJson(route('guest.invitations.show', ['token' => $original->token]))->assertNotFound();
        $this->getJson(route('guest.invitations.show', ['token' => $replacement->token]))->assertOk();

        $meeting->update(['status' => MeetingStatus::Ended]);
        $this->getJson(route('guest.invitations.show', ['token' => $replacement->token]))->assertNotFound();
    }

    public function test_unrelated_teacher_student_anonymous_and_cross_class_requests_cannot_manage_invitations(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext(MeetingStatus::Scheduled);
        $unrelated = User::factory()->create();
        $unrelated->assignRole('Teacher');
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => 'active']);
        $issued = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $show = route('guest-invitations.show', [$class, $meeting, $issued->invitation]);
        $rotate = route('guest-invitations.rotate', [$class, $meeting, $issued->invitation]);
        $guest = app(GuestMeetingSessionService::class)->create($issued->invitation, 'Guest One');

        $this->postJson(route('guest-invitations.store', [$class, $meeting]))->assertUnauthorized();
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $guest->credential)->getJson($show)->assertUnauthorized();
        $this->actingAs($student)->postJson(route('guest-invitations.store', [$class, $meeting]))->assertForbidden();
        $this->actingAs($student)->deleteJson($show)->assertForbidden();
        $this->actingAs($unrelated)->postJson($rotate)->assertForbidden();
        $this->actingAs($host)->postJson(route('guest-invitations.store', [$otherClass, $meeting]))->assertNotFound();
        $this->actingAs($host)->getJson($show)->assertOk();
        $this->actingAs($host)->postJson($rotate)->assertOk();

        $revocable = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $this->actingAs($host)
            ->deleteJson(route('guest-invitations.destroy', [$class, $meeting, $revocable->invitation]))
            ->assertOk()
            ->assertJsonPath('invitation.revoked_at', fn ($value) => $value !== null);
    }

    public function test_guest_session_uses_a_cookie_only_persists_a_hash_and_duplicate_names_get_distinct_identities(): void
    {
        [, $host, , $meeting] = $this->meetingContext(MeetingStatus::Scheduled);
        $issued = app(GuestMeetingInvitationService::class)->create($host, $meeting);

        $first = $this->postJson(route('guest.sessions.store', ['token' => $issued->token]), ['display_name' => '  Guest   Name  ']);
        $first->assertCreated()->assertJsonPath('session.display_name', 'Guest Name')->assertCookie(config('guest-meetings.cookie_name'));
        $second = $this->postJson(route('guest.sessions.store', ['token' => $issued->token]), ['display_name' => 'Guest Name'])->assertCreated();

        $sessions = GuestMeetingSession::query()->orderBy('id')->get();
        $this->assertCount(2, $sessions);
        $this->assertNotSame($sessions[0]->public_uuid, $sessions[1]->public_uuid);
        $this->assertSame(GuestMeetingSessionStatus::Pending, $sessions[0]->status);
        $this->assertDatabaseMissing('guest_meeting_sessions', ['credential_hash' => $first->json('session.public_uuid')]);
        $this->assertStringNotContainsString('credential', json_encode($first->json(), JSON_THROW_ON_ERROR));
    }

    public function test_guest_session_status_requires_the_matching_cookie_and_rejects_invalidated_or_mismatched_state(): void
    {
        [, $host, , $meeting] = $this->meetingContext(MeetingStatus::Scheduled);
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $issued = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');
        $url = route('guest.sessions.show', ['guestSession' => $issued->session->public_uuid]);

        $this->getJson($url)->assertNotFound();
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $issued->credential)->getJson($url)->assertOk()
            ->assertJsonPath('session.status', 'pending');
        $issued->session->update(['invalidated_at' => now(), 'status' => GuestMeetingSessionStatus::Expired]);
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $issued->credential)->getJson($url)->assertNotFound();

        $other = Meeting::factory()->create(['school_class_id' => $meeting->school_class_id, 'host_user_id' => $host->id]);
        $issued->session->update(['invalidated_at' => null, 'status' => GuestMeetingSessionStatus::Pending, 'meeting_id' => $other->id]);
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $issued->credential)->getJson($url)->assertNotFound();
    }

    public function test_guest_credentials_do_not_unlock_authenticated_token_recording_moderation_or_dashboard_routes_and_do_not_log_out_a_normal_user(): void
    {
        [$class, $host, , $meeting] = $this->meetingContext(MeetingStatus::Active);
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $issued = app(GuestMeetingSessionService::class)->create($invite->invitation, 'Guest One');

        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $issued->credential)
            ->postJson(route('meetings.token', [$class, $meeting]))->assertUnauthorized();
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $issued->credential)
            ->get('/dashboard')->assertRedirect(route('login'));
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $issued->credential)
            ->getJson(route('meetings.recordings.play', [$class, $meeting, 999999]))->assertUnauthorized();
        $this->withCredentials()->withCookie(config('guest-meetings.cookie_name'), $issued->credential)
            ->patchJson(route('meetings.participants.mute', [$class, $meeting, (string) Str::uuid()]))->assertUnauthorized();

        $this->actingAs($host)
            ->postJson(route('guest.sessions.store', ['token' => $invite->token]), ['display_name' => 'Shared Browser Guest'])
            ->assertCreated();
        $this->assertAuthenticatedAs($host);
        $this->get('/dashboard')->assertOk();
    }

    public function test_guest_session_creation_requires_csrf_and_display_name_validation(): void
    {
        [, $host, , $meeting] = $this->meetingContext(MeetingStatus::Scheduled);
        $invite = app(GuestMeetingInvitationService::class)->create($host, $meeting);

        $this->postJson(route('guest.sessions.store', ['token' => $invite->token]), ['display_name' => ''])->assertUnprocessable()->assertJsonValidationErrors('display_name');
        $this->postJson(route('guest.sessions.store', ['token' => $invite->token]), ['display_name' => str_repeat('a', 81)])->assertUnprocessable()->assertJsonValidationErrors('display_name');

        $route = Route::getRoutes()->getByName('guest.sessions.store');
        $this->assertContains('web', $route->gatherMiddleware());

        $csrf = new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $request = Request::create('/meetings/guest/example/sessions', 'POST');
        $request->setLaravelSession($this->app['session']->driver());

        $this->expectException(TokenMismatchException::class);
        $csrf->handle($request, fn () => response()->noContent());
    }

    public function test_public_validation_is_rate_limited_without_storing_failed_attempts(): void
    {
        $token = str_repeat('b', 64);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->getJson(route('guest.invitations.show', ['token' => $token]))->assertNotFound();
        }

        $this->getJson(route('guest.invitations.show', ['token' => $token]))->assertTooManyRequests();
        $this->assertDatabaseCount('guest_meeting_sessions', 0);
    }

    private function meetingContext(MeetingStatus $status): array
    {
        $class = SchoolClass::factory()->create([
            'academic_year_id' => AcademicYear::factory()->active(),
            'status' => SchoolClassStatus::Active,
        ]);
        $host = User::factory()->create();
        $host->assignRole('Teacher');
        $teacher = TeacherProfile::factory()->create(['user_id' => $host->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $teacher->id, 'school_class_id' => $class->id, 'current_slot' => 1]);
        $student = User::factory()->create();
        $student->assignRole('Student');
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => $status]);

        return [$class, $host, $student, $meeting];
    }

    private function tokenFrom(string $url): string
    {
        return basename((string) parse_url($url, PHP_URL_PATH));
    }
}
