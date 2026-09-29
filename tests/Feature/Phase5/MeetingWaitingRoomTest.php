<?php

namespace Tests\Feature\Phase5;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\VideoGrant;
use App\Enums\MeetingJoinRequestStatus;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingJoinRequest;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fakes\FakeLiveKitTokenIssuer;
use Tests\TestCase;

class MeetingWaitingRoomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(LiveKitTokenIssuer::class, new FakeLiveKitTokenIssuer);
        config(['livekit.api_key' => 'test-key', 'livekit.api_secret' => 'test-secret-that-is-at-least-32-bytes']);
    }

    public function test_host_bypasses_waiting_room_but_student_requires_admission_before_a_token(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $this->actingAs($host)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertForbidden();

        $this->actingAs($student)->postJson(route('meetings.waiting-room.store', [$class, $meeting]))->assertCreated()->assertJsonPath('request.status', 'pending');
        $request = MeetingJoinRequest::query()->firstOrFail();
        $this->actingAs($host)->patchJson(route('meetings.join-requests.update', [$class, $meeting, $request]), ['decision' => 'admitted'])->assertOk()->assertJsonPath('request.status', 'admitted');
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
    }

    public function test_duplicate_pending_requests_are_reused_and_do_not_create_attendance(): void
    {
        [$class, , $student, $meeting] = $this->meetingContext();
        $this->actingAs($student)->postJson(route('meetings.waiting-room.store', [$class, $meeting]))->assertCreated();
        $this->actingAs($student)->postJson(route('meetings.waiting-room.store', [$class, $meeting]))->assertOk();

        $this->assertDatabaseCount('meeting_join_requests', 1);
        $this->assertDatabaseCount('meeting_participants', 0);
        $this->assertDatabaseCount('meeting_attendance_sessions', 0);
    }

    public function test_denied_request_cannot_issue_a_token_and_unrelated_or_cross_meeting_hosts_cannot_decide(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $request = MeetingJoinRequest::factory()->create(['meeting_id' => $meeting->id, 'requester_user_id' => $student->id, 'status' => MeetingJoinRequestStatus::Pending]);
        $unrelated = User::factory()->create();
        $unrelated->assignRole('Teacher');
        $this->actingAs($unrelated)->patchJson(route('meetings.join-requests.update', [$class, $meeting, $request]), ['decision' => 'admitted'])->assertForbidden();

        $otherMeeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);
        $this->actingAs($host)->patchJson(route('meetings.join-requests.update', [$class, $otherMeeting, $request]), ['decision' => 'admitted'])->assertNotFound();
        $this->actingAs($host)->patchJson(route('meetings.join-requests.update', [$class, $meeting, $request]), ['decision' => 'denied'])->assertOk();
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertForbidden();
    }

    public function test_transport_disconnect_preserves_admission_but_explicit_leave_cancels_it(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $this->actingAs($student)->postJson(route('meetings.waiting-room.store', [$class, $meeting]))->assertCreated();
        $request = MeetingJoinRequest::query()->firstOrFail();
        $this->actingAs($host)->patchJson(route('meetings.join-requests.update', [$class, $meeting, $request]), ['decision' => 'admitted'])->assertOk();
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();

        $request->update(['decided_at' => now()->subMinutes(2)]);
        $meeting->participants()->where('user_id', $student->id)->firstOrFail()->update(['last_left_at' => now()->subMinute()]);

        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
        $this->actingAs($student)->postJson(route('meetings.waiting-room.store', [$class, $meeting]))->assertOk()
            ->assertJsonPath('request.status', 'admitted')->assertJsonPath('request.can_enter', true);

        $this->actingAs($student)->deleteJson(route('meetings.waiting-room.destroy', [$class, $meeting]))
            ->assertOk()->assertJsonPath('request.status', 'cancelled');
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertForbidden();
        $this->actingAs($student)->postJson(route('meetings.waiting-room.store', [$class, $meeting]))->assertOk()
            ->assertJsonPath('request.status', 'pending')->assertJsonPath('request.can_enter', false);
        $this->assertDatabaseCount('meeting_join_requests', 1);
        $this->actingAs($host)->getJson(route('meetings.join-requests.index', [$class, $meeting]))->assertOk()->assertJsonCount(1, 'requests');
        $this->actingAs($host)->patchJson(route('meetings.join-requests.update', [$class, $meeting, $request]), ['decision' => 'admitted'])->assertOk();
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
    }

    public function test_admitted_student_can_resume_after_old_connection_leave_webhook_races_with_refresh(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $meeting->update(['session_started_at' => now()->subMinutes(5)]);
        $meeting->refresh();
        $startedAt = $meeting->session_started_at->toIso8601String();
        $request = MeetingJoinRequest::factory()->create([
            'meeting_id' => $meeting->id,
            'requester_user_id' => $student->id,
            'status' => MeetingJoinRequestStatus::Admitted,
            'requested_at' => now()->subMinutes(2),
            'decided_at' => now()->subMinute(),
            'decided_by' => $host->id,
        ]);

        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))
            ->assertOk()->assertJsonPath('session_started_at', $startedAt);
        $participant = MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('user_id', $student->id)->firstOrFail();
        $this->postWebhook($this->participantWebhook('participant_joined', $meeting, $participant, 'PA_initial'))->assertOk();

        $this->travel(1)->minutes();
        $this->postWebhook($this->participantWebhook('participant_left', $meeting, $participant, 'PA_initial'))->assertOk();
        $this->assertSame(MeetingJoinRequestStatus::Admitted, $request->fresh()->status);
        $this->assertTrue($request->fresh()->admitsCurrentEntry());
        $this->assertNotNull($participant->fresh()->last_left_at);
        $this->assertNotNull(MeetingAttendanceSession::query()->where('livekit_participant_sid', 'PA_initial')->firstOrFail()->left_at);

        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))
            ->assertOk()->assertJsonPath('session_started_at', $startedAt);
        $this->postWebhook($this->participantWebhook('participant_joined', $meeting, $participant, 'PA_refresh_one'))->assertOk();

        $this->travel(1)->minutes();
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))
            ->assertOk()->assertJsonPath('session_started_at', $startedAt);
        $this->postWebhook($this->participantWebhook('participant_left', $meeting, $participant, 'PA_refresh_one'))->assertOk();
        $this->assertSame(MeetingJoinRequestStatus::Admitted, $request->fresh()->status);
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))
            ->assertOk()->assertJsonPath('session_started_at', $startedAt);
        $this->postWebhook($this->participantWebhook('participant_joined', $meeting, $participant, 'PA_refresh_two'))->assertOk();

        $this->assertSame(3, MeetingAttendanceSession::query()->where('meeting_participant_id', $participant->id)->count());
        $this->assertSame(1, MeetingAttendanceSession::query()->where('meeting_participant_id', $participant->id)->whereNull('left_at')->count());
        $this->assertSame(MeetingJoinRequestStatus::Admitted, $request->fresh()->status);
        $this->assertSame($startedAt, $meeting->fresh()->session_started_at->toIso8601String());
    }

    public function test_denied_participant_can_retry_as_a_fresh_pending_request_without_token_access(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $this->actingAs($student)->postJson(route('meetings.waiting-room.store', [$class, $meeting]))->assertCreated();
        $request = MeetingJoinRequest::query()->firstOrFail();
        $this->actingAs($host)->patchJson(route('meetings.join-requests.update', [$class, $meeting, $request]), ['decision' => 'denied'])->assertOk();
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertForbidden();

        $this->actingAs($student)->postJson(route('meetings.waiting-room.store', [$class, $meeting]))->assertOk()
            ->assertJsonPath('request.status', 'pending')->assertJsonPath('request.can_enter', false);
        $this->assertDatabaseCount('meeting_join_requests', 1);
        $this->actingAs($host)->getJson(route('meetings.join-requests.index', [$class, $meeting]))->assertOk()->assertJsonCount(1, 'requests');
    }

    public function test_waiting_state_persists_on_refresh_and_ended_meetings_reject_join_and_decisions(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $request = MeetingJoinRequest::factory()->create(['meeting_id' => $meeting->id, 'requester_user_id' => $student->id]);
        $this->actingAs($student)->getJson(route('meetings.waiting-room.show', [$class, $meeting]))->assertOk()->assertJsonPath('request.status', 'pending');
        $meeting->update(['status' => MeetingStatus::Ended]);
        $this->actingAs($student)->postJson(route('meetings.waiting-room.store', [$class, $meeting]))->assertForbidden();
        $this->actingAs($host)->patchJson(route('meetings.join-requests.update', [$class, $meeting, $request]), ['decision' => 'admitted'])->assertForbidden();
    }

    public function test_only_assigned_host_can_poll_pending_requests_and_decisions_remove_them_from_the_list(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $first = MeetingJoinRequest::factory()->create(['meeting_id' => $meeting->id, 'requester_user_id' => $student->id]);
        $second = MeetingJoinRequest::factory()->create(['meeting_id' => $meeting->id]);
        $this->actingAs($host)->getJson(route('meetings.join-requests.index', [$class, $meeting]))->assertOk()
            ->assertJsonCount(2, 'requests')->assertJsonPath('requests.0.reference', $first->public_uuid);
        $this->actingAs($student)->getJson(route('meetings.join-requests.index', [$class, $meeting]))->assertForbidden();
        $unrelated = User::factory()->create();
        $unrelated->assignRole('Teacher');
        $this->actingAs($unrelated)->getJson(route('meetings.join-requests.index', [$class, $meeting]))->assertForbidden();
        $this->actingAs($host)->patchJson(route('meetings.join-requests.update', [$class, $meeting, $first]), ['decision' => 'admitted'])->assertOk();
        $this->actingAs($host)->getJson(route('meetings.join-requests.index', [$class, $meeting]))->assertOk()
            ->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.reference', $second->public_uuid);
    }

    public function test_waiting_room_exposes_only_a_safe_requester_avatar_url_to_the_authorized_host(): void
    {
        [$class, $host, $student, $meeting] = $this->meetingContext();
        $student->update(['avatar_path' => "user-avatars/{$student->id}/profile.png"]);
        $request = MeetingJoinRequest::factory()->create(['meeting_id' => $meeting->id, 'requester_user_id' => $student->id]);

        $this->actingAs($host)->getJson(route('meetings.join-requests.index', [$class, $meeting]))
            ->assertOk()
            ->assertJsonPath('requests.0.reference', $request->public_uuid)
            ->assertJsonPath('requests.0.avatar_url', Storage::disk('public')->url("user-avatars/{$student->id}/profile.png"))
            ->assertJsonMissingPath('requests.0.avatar_path')
            ->assertJsonMissingPath('requests.0.requester_user_id');
    }

    public function test_live_room_contains_the_host_only_waiting_room_polling_panel(): void
    {
        $component = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));
        $moderation = file_get_contents(resource_path('js/Hooks/Meetings/useMeetingModeration.js'));
        $sidePanel = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingSidePanel.jsx'));

        foreach (['useMeetingModeration', 'WaitingSection', 'MeetingParticipantAvatar', 'waiting-room/requests', 'window.setTimeout(refresh, 5000)', 'window.clearTimeout(timer)', 'meeting.can_manage_join_requests', "t('meetingRoom.waitingRoom.empty')"] as $contract) {
            $this->assertStringContainsString($contract, $component.$moderation.$sidePanel);
        }

        $this->assertStringNotContainsString('usePage(', $component.$moderation.$sidePanel);
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
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        Enrollment::factory()->create(['student_profile_id' => $profile->id, 'school_class_id' => $class->id, 'academic_year_id' => $class->academic_year_id, 'current_slot' => 1]);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);

        return [$class, $host, $student, $meeting];
    }

    private function participantWebhook(string $event, Meeting $meeting, MeetingParticipant $participant, string $sid): array
    {
        return [
            'event' => $event,
            'id' => (string) Str::uuid(),
            'createdAt' => now()->timestamp,
            'room' => ['name' => $meeting->livekit_room_name],
            'participant' => ['identity' => $participant->livekit_identity, 'sid' => $sid],
        ];
    }

    private function postWebhook(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $token = (new AccessToken('test-key', 'test-secret-that-is-at-least-32-bytes'))
            ->setGrant(new VideoGrant)
            ->setSha256(base64_encode(hash('sha256', $body, true)))
            ->toJwt();

        return $this->call('POST', route('integrations.livekit.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], $body);
    }
}
