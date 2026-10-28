<?php

namespace Tests\Feature;

use App\Enums\GuestMeetingSessionStatus;
use App\Enums\MeetingParticipantRole;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\GuestMeetings\GuestMeetingInvitationService;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLiveKitTokenIssuer;
use Tests\TestCase;

class GuestMeetingParticipationTest extends TestCase
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

    public function test_only_an_admitted_guest_can_receive_a_room_scoped_least_privilege_token(): void
    {
        [$class, $host, , $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);

        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertOk()
            ->assertJsonPath('participant.role', 'guest')
            ->assertJsonMissingPath('participant.id');

        $participant = $guest->session->fresh()->participant;
        $this->assertNotNull($participant);
        $this->assertNull($participant->user_id);
        $this->assertSame(MeetingParticipantRole::Guest, $participant->role);
        $this->assertSame($meeting->livekit_room_name, $this->issuer->roomName);
        $this->assertSame($participant->livekit_identity, $this->issuer->identity);
        $this->assertNotSame($guest->session->display_name_snapshot, $this->issuer->identity);
        $this->assertSame(['camera', 'microphone'], $this->issuer->publishSources);
        $this->assertFalse($this->issuer->canPublishData);
        $this->assertSame(['participant_type' => 'guest'], json_decode((string) $this->issuer->metadata, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(1, $this->issuer->calls);
    }

    public function test_pending_rejected_removed_expired_and_ended_sessions_cannot_receive_tokens(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $pending = $this->guest($host, $meeting);
        $this->guestRequest($pending)->postJson($this->tokenUrl($pending))->assertForbidden();

        $rejected = $this->guest($host, $meeting);
        app(GuestMeetingSessionService::class)->decide($host, $rejected->session, GuestMeetingSessionStatus::Rejected);
        $this->guestRequest($rejected)->postJson($this->tokenUrl($rejected))->assertNotFound();

        $removed = $this->guest($host, $meeting);
        $removed->session->update(['status' => GuestMeetingSessionStatus::Removed, 'invalidated_at' => now()]);
        $this->guestRequest($removed)->postJson($this->tokenUrl($removed))->assertNotFound();

        $expired = $this->guest($host, $meeting);
        $expired->session->update(['expires_at' => now()->subSecond()]);
        $this->guestRequest($expired)->postJson($this->tokenUrl($expired))->assertNotFound();

        $ended = $this->guest($host, $meeting, admitted: true);
        $meeting->update(['status' => MeetingStatus::Ended]);
        $this->guestRequest($ended)->postJson($this->tokenUrl($ended))->assertNotFound();
        $this->assertSame(0, $this->issuer->calls);
    }

    public function test_invalid_credentials_and_cross_meeting_relationships_are_denied(): void
    {
        [$class, $host, , $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        $this->withCookie(config('guest-meetings.cookie_name'), str_repeat('a', 64))->postJson($this->tokenUrl($guest))->assertNotFound();

        $other = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Active]);
        $guest->session->update(['meeting_id' => $other->id]);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertNotFound();
        $this->assertSame(0, $this->issuer->calls);
    }

    public function test_unauthorized_host_decision_never_enables_guest_access(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting);
        $decision = route('meetings.guest-requests.update', [$class, $meeting, $guest->session]);

        $this->actingAs($student)->patchJson($decision, ['decision' => 'admitted'])->assertForbidden();
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertForbidden();
        $this->assertSame(0, $this->issuer->calls);
    }

    public function test_duplicate_display_names_have_distinct_opaque_guest_identities(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $first = $this->guest($host, $meeting, admitted: true, name: 'Guest');
        $second = $this->guest($host, $meeting, admitted: true, name: 'Guest');

        $this->guestRequest($first)->postJson($this->tokenUrl($first))->assertOk();
        $firstIdentity = $this->issuer->identity;
        $this->guestRequest($second)->postJson($this->tokenUrl($second))->assertOk();

        $this->assertNotSame($firstIdentity, $this->issuer->identity);
        $this->assertSame(2, MeetingParticipant::query()->where('meeting_id', $meeting->id)->whereNull('user_id')->count());
    }

    public function test_guest_capacity_reservations_are_counted_and_reused_without_duplicate_rows(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $meeting->update(['max_participants' => 1]);
        $first = $this->guest($host, $meeting, admitted: true);
        $second = $this->guest($host, $meeting, admitted: true);

        $this->guestRequest($first)->postJson($this->tokenUrl($first))->assertOk();
        $this->guestRequest($first)->postJson($this->tokenUrl($first))->assertOk();
        $this->assertSame(1, MeetingParticipant::query()->where('meeting_id', $meeting->id)->count());
        $this->guestRequest($second)->postJson($this->tokenUrl($second))->assertUnprocessable();
        $this->assertSame(1, MeetingParticipant::query()->where('meeting_id', $meeting->id)->count());
        $this->assertNull($second->session->fresh()->meeting_participant_id);
    }

    public function test_removed_linked_guest_cannot_reissue_a_token(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertOk();
        $guest->session->fresh()->participant->update(['removed_at' => now(), 'removed_by' => $host->id]);

        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertForbidden();
        $this->assertSame(1, $this->issuer->calls);
    }

    public function test_revoking_an_invitation_does_not_evict_an_admitted_guest_but_new_guest_token_paths_remain_scoped(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        app(GuestMeetingInvitationService::class)->revoke($host, $guest->invitation);

        $this->guestRequest($guest)->postJson($this->tokenUrl($guest))->assertOk();
        $this->guestRequest($guest)->postJson(route('meetings.token', [$meeting->schoolClass, $meeting]))->assertUnauthorized();
        $this->guestRequest($guest)->get('/dashboard')->assertRedirect(route('login'));
        $this->guestRequest($guest)->get('/classes')->assertRedirect(route('login'));
        $this->guestRequest($guest)->getJson(route('meetings.recordings.play', [$meeting->schoolClass, $meeting, 99999]))->assertUnauthorized();
    }

    public function test_admitted_guest_room_page_requires_the_same_active_guest_session(): void
    {
        [, $host, , $meeting] = $this->meetingContext();
        $guest = $this->guest($host, $meeting, admitted: true);
        $this->guestRequest($guest)->get($this->roomUrl($guest))->assertOk()
            ->assertInertia(fn ($page) => $page->component('GuestMeetings/Room')->where('session.display_name', $guest->session->display_name_snapshot));

        $meeting->update(['status' => MeetingStatus::Ended]);
        $this->guestRequest($guest)->get($this->roomUrl($guest))->assertNotFound();
    }

    private function guest(User $host, Meeting $meeting, bool $admitted = false, string $name = 'Guest One'): object
    {
        $invitation = app(GuestMeetingInvitationService::class)->create($host, $meeting);
        $issued = app(GuestMeetingSessionService::class)->create($invitation->invitation, $name);
        if ($admitted) {
            app(GuestMeetingSessionService::class)->decide($host, $issued->session, GuestMeetingSessionStatus::Admitted);
        }

        return (object) ['session' => $issued->session->fresh(), 'credential' => $issued->credential, 'invitation' => $invitation->invitation];
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
