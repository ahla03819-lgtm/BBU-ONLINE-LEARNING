<?php

namespace Tests\Feature\Phase7;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\VideoGrant;
use App\Actions\Meetings\DecideMeetingScreenShareRequest;
use App\Actions\Meetings\ReconcileMeetingScreenShareState;
use App\Contracts\MeetingLifecycleProvider;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingScreenShareReconciliation;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Jobs\ExpireMeetingScreenShareApproval;
use App\Jobs\ProcessLiveKitWebhook;
use App\Jobs\ReconcileMeetingScreenSharePublication;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\LiveKitWebhookEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingJoinRequest;
use App\Models\MeetingParticipant;
use App\Models\MeetingScreenShareRequest;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitParticipantPresence;
use App\Services\LiveKit\LiveKitRoomManager;
use App\Services\LiveKit\LiveKitScreenShareState;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livekit\TrackSource;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Fakes\FakeLiveKitTokenIssuer;
use Tests\Fakes\FakeMeetingLifecycleProvider;
use Tests\TestCase;

class MeetingScreenShareApprovalTest extends TestCase
{
    use RefreshDatabase;

    /** The provider SID the default stub reports as currently connected. */
    private const CURRENT_SID = 'PA_current_presence';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['livekit.api_key' => 'test-key', 'livekit.api_secret' => 'test-secret-that-is-at-least-32-bytes']);
        // Default: the student is genuinely connected to the room. Screen-share
        // authorisation is decided by live provider presence, never by a local
        // attendance row. Tests that bind their own mock before issuing a request
        // must declare participantPresence on it themselves.
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('participantPresence')->andReturn(new LiveKitParticipantPresence(
            MeetingProviderState::Active,
            self::CURRENT_SID,
            CarbonImmutable::parse('2026-09-30T09:00:00+00:00'),
        ));
        $rooms->shouldReceive('setParticipantScreenSharePermission')->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('mutePublishedTrack')->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('removeParticipant')->andReturn(MeetingProviderState::Ended);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
    }

    /**
     * Strict current-presence proof: the provider is asked with exactly the
     * server-derived room name and identity, never a browser-supplied value.
     */
    private function presentNow(MockInterface $rooms, Meeting $meeting, MeetingParticipant $participant, string $participantSid = self::CURRENT_SID): void
    {
        $rooms->shouldReceive('participantPresence')
            ->with($meeting->livekit_room_name, $participant->livekit_identity)
            ->andReturn(new LiveKitParticipantPresence(
                MeetingProviderState::Active,
                $participantSid,
                CarbonImmutable::parse('2026-09-30T09:00:00+00:00'),
            ));
    }

    private function presenceState(MockInterface $rooms, Meeting $meeting, MeetingParticipant $participant, MeetingProviderState $state): void
    {
        $rooms->shouldReceive('participantPresence')
            ->with($meeting->livekit_room_name, $participant->livekit_identity)
            ->andReturn(new LiveKitParticipantPresence($state));
    }

    /**
     * The provider reports a currently published canonical SCREEN_SHARE VIDEO
     * track. Both arguments are server-derived and the track SID is the
     * provider's own, never anything a client supplied.
     */
    private function screenVideoPresent(MockInterface $rooms, Meeting $meeting, MeetingParticipant $participant, string $trackSid = 'TR_provider_screen_video'): void
    {
        $rooms->shouldReceive('screenShareState')
            ->with($meeting->livekit_room_name, $participant->livekit_identity)
            ->andReturn(new LiveKitScreenShareState(MeetingProviderState::Active, $trackSid, []));
    }

    /**
     * The provider authoritatively reports the participant with no screen-share
     * video published.
     */
    private function notSharing(MockInterface $rooms, Meeting $meeting, MeetingParticipant $participant): void
    {
        $rooms->shouldReceive('screenShareState')
            ->with($meeting->livekit_room_name, $participant->livekit_identity)
            ->andReturn(new LiveKitScreenShareState(MeetingProviderState::Active, null, []));
    }

    /**
     * The provider could not be asked. This is never a verdict and must never be
     * treated as "the student never shared".
     */
    private function providerUnknown(MockInterface $rooms, Meeting $meeting, MeetingParticipant $participant): void
    {
        $rooms->shouldReceive('screenShareState')
            ->with($meeting->livekit_room_name, $participant->livekit_identity)
            ->andReturn(new LiveKitScreenShareState(MeetingProviderState::Unknown, null, []));
    }

    /**
     * Only the screen-share audio companion is published. Audio alone is never
     * proof that a screen share started.
     */
    private function audioOnly(MockInterface $rooms, Meeting $meeting, MeetingParticipant $participant): void
    {
        $rooms->shouldReceive('screenShareState')
            ->with($meeting->livekit_room_name, $participant->livekit_identity)
            ->andReturn(new LiveKitScreenShareState(MeetingProviderState::Active, null, ['TR_provider_screen_audio']));
    }

    public function test_a_student_with_no_local_attendance_is_reconciled_from_current_provider_presence(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        // A broken webhook means no row exists at all. Live presence is the only
        // authority, so the request is honoured and the row is reconciled.
        MeetingAttendanceSession::query()->where('meeting_participant_id', $participant->id)->delete();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending');

        $reconciled = $participant->attendanceSessions()->firstOrFail();
        $this->assertSame($participant->id, $reconciled->meeting_participant_id);
        $this->assertSame(self::CURRENT_SID, $reconciled->livekit_participant_sid);
        // No webhook backs this row, so no webhook event is invented for it.
        $this->assertNull($reconciled->join_webhook_event_id);
        $this->assertNull($reconciled->left_at);
        $this->assertDatabaseHas('meeting_screen_share_requests', [
            'meeting_id' => $meeting->id,
            'meeting_participant_id' => $participant->id,
            'requester_user_id' => $student->id,
            'status' => MeetingScreenShareRequestStatus::Pending->value,
            'active_slot' => 1,
        ]);
    }

    public function test_current_presence_is_queried_even_when_a_matching_open_attendance_row_exists(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $existing = $participant->attendanceSessions()->firstOrFail();
        $joinedAt = $existing->joined_at;
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();

        // The row is reused, never duplicated, and its history is untouched.
        $this->assertSame(1, $participant->attendanceSessions()->count());
        $this->assertTrue($existing->is($participant->attendanceSessions()->firstOrFail()));
        $this->assertTrue($joinedAt->equalTo($existing->fresh()->joined_at));
    }

    public function test_a_stale_open_attendance_row_never_authorises_when_the_provider_says_the_participant_left(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presenceState($rooms, $meeting, $participant, MeetingProviderState::Ended);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))
            ->assertStatus(422)->assertJsonValidationErrors('screen_share');

        $this->assertSame(0, MeetingScreenShareRequest::query()->where('meeting_participant_id', $participant->id)->count());
        $this->assertNull($participant->attendanceSessions()->firstOrFail()->left_at);
    }

    public function test_a_stale_open_attendance_row_never_authorises_when_the_provider_is_unavailable(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presenceState($rooms, $meeting, $participant, MeetingProviderState::Unknown);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))
            ->assertStatus(422)->assertJsonValidationErrors('screen_share');

        $this->assertSame(0, MeetingScreenShareRequest::query()->where('meeting_participant_id', $participant->id)->count());
    }

    public function test_a_stale_different_sid_row_is_not_evidence_and_the_current_sid_is_reconciled(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $stale = $participant->attendanceSessions()->firstOrFail();
        $stale->update(['livekit_participant_sid' => 'PA_previous_connection']);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();

        $current = $participant->attendanceSessions()->where('livekit_participant_sid', self::CURRENT_SID)->firstOrFail();
        $this->assertNull($current->left_at);
        // The stale row is history: still open, never rewritten, never used.
        $this->assertNull($stale->fresh()->left_at);
        $this->assertSame(2, $participant->attendanceSessions()->count());
    }

    public function test_a_closed_row_for_the_current_provider_sid_fails_closed_instead_of_reopening_history(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $closed = $participant->attendanceSessions()->firstOrFail();
        $closed->update(['left_at' => now()->subHour(), 'leave_reason' => 'participant_left']);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))
            ->assertStatus(422)->assertJsonValidationErrors('screen_share');

        $this->assertSame(1, $participant->attendanceSessions()->count());
        $this->assertSame('participant_left', $closed->fresh()->leave_reason);
        $this->assertSame(0, MeetingScreenShareRequest::query()->where('meeting_participant_id', $participant->id)->count());
    }

    public function test_repeated_share_clicks_never_duplicate_the_current_attendance_session(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        MeetingAttendanceSession::query()->where('meeting_participant_id', $participant->id)->delete();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $first = $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();
        $second = $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();
        $third = $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();

        $this->assertSame($first->json('request.reference'), $second->json('request.reference'));
        $this->assertSame($first->json('request.reference'), $third->json('request.reference'));
        $this->assertSame(1, MeetingScreenShareRequest::query()->where('meeting_participant_id', $participant->id)->where('active_slot', 1)->count());
        $this->assertSame(1, $participant->attendanceSessions()->count());
    }

    public function test_a_reconciled_row_is_adopted_by_a_later_participant_joined_webhook_without_duplication(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        MeetingAttendanceSession::query()->where('meeting_participant_id', $participant->id)->delete();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();
        $reconciled = $participant->attendanceSessions()->firstOrFail();
        $this->assertNull($reconciled->join_webhook_event_id);
        $authoritativeJoinedAt = $reconciled->joined_at;

        $joined = LiveKitWebhookEvent::factory()->create([
            'event_type' => 'participant_joined',
            'livekit_room_name' => $meeting->livekit_room_name,
            'participant_identity' => $participant->livekit_identity,
            'participant_sid' => self::CURRENT_SID,
            'occurred_at' => now()->addMinutes(5),
        ]);
        (new ProcessLiveKitWebhook($joined->event_id))->handle($rooms, app(AuditLogger::class));

        $this->assertSame(1, $participant->attendanceSessions()->count());
        $adopted = $participant->attendanceSessions()->firstOrFail();
        $this->assertTrue($reconciled->is($adopted));
        $this->assertSame($joined->event_id, $adopted->join_webhook_event_id);
        // The provider's own join time stays authoritative.
        $this->assertTrue($authoritativeJoinedAt->equalTo($adopted->joined_at));

        // Redelivery of the same webhook changes nothing.
        (new ProcessLiveKitWebhook($joined->event_id))->handle($rooms, app(AuditLogger::class));
        $this->assertSame(1, $participant->attendanceSessions()->count());
        $this->assertSame($joined->event_id, $adopted->fresh()->join_webhook_event_id);
    }

    public function test_a_webhook_opened_row_is_reused_by_reconciliation_without_duplication(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        MeetingAttendanceSession::query()->where('meeting_participant_id', $participant->id)->delete();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $joined = LiveKitWebhookEvent::factory()->create([
            'event_type' => 'participant_joined',
            'livekit_room_name' => $meeting->livekit_room_name,
            'participant_identity' => $participant->livekit_identity,
            'participant_sid' => self::CURRENT_SID,
        ]);
        (new ProcessLiveKitWebhook($joined->event_id))->handle($rooms, app(AuditLogger::class));
        $webhookRow = $participant->attendanceSessions()->firstOrFail();

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();

        $this->assertSame(1, $participant->attendanceSessions()->count());
        $this->assertTrue($webhookRow->is($participant->attendanceSessions()->firstOrFail()));
        $this->assertSame($joined->event_id, $webhookRow->fresh()->join_webhook_event_id);
    }

    public function test_a_duplicate_participant_joined_webhook_still_yields_one_attendance_row(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        MeetingAttendanceSession::query()->where('meeting_participant_id', $participant->id)->delete();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $joined = LiveKitWebhookEvent::factory()->create([
            'event_type' => 'participant_joined',
            'livekit_room_name' => $meeting->livekit_room_name,
            'participant_identity' => $participant->livekit_identity,
            'participant_sid' => 'PA_duplicate_delivery',
        ]);

        (new ProcessLiveKitWebhook($joined->event_id))->handle($rooms, app(AuditLogger::class));
        (new ProcessLiveKitWebhook($joined->event_id))->handle($rooms, app(AuditLogger::class));

        $this->assertSame(1, $participant->attendanceSessions()->count());
        $this->assertSame('PA_duplicate_delivery', $participant->attendanceSessions()->firstOrFail()->livekit_participant_sid);
    }

    public function test_participant_left_closes_a_reconciled_attendance_row(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        MeetingAttendanceSession::query()->where('meeting_participant_id', $participant->id)->delete();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        // The pending request is revoked by the leave webhook before the session closes.
        $rooms->shouldReceive('setParticipantScreenSharePermission')->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();
        $reconciled = $participant->attendanceSessions()->firstOrFail();
        $this->assertNull($reconciled->left_at);

        $left = LiveKitWebhookEvent::factory()->create([
            'event_type' => 'participant_left',
            'livekit_room_name' => $meeting->livekit_room_name,
            'participant_identity' => $participant->livekit_identity,
            'participant_sid' => self::CURRENT_SID,
        ]);
        (new ProcessLiveKitWebhook($left->event_id))->handle($rooms, app(AuditLogger::class));

        $closed = $reconciled->fresh();
        $this->assertNotNull($closed->left_at);
        $this->assertSame('participant_left', $closed->leave_reason);
        $this->assertSame($left->event_id, $closed->leave_webhook_event_id);
        $this->assertSame(1, $participant->attendanceSessions()->count());
    }

    public function test_a_removed_participant_is_denied_before_the_provider_is_ever_asked(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $participant->update(['removed_at' => now()]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldNotReceive('participantPresence');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertForbidden();

        $this->assertSame(0, MeetingScreenShareRequest::query()->where('meeting_participant_id', $participant->id)->count());
    }

    public function test_another_users_participant_and_a_cross_meeting_scope_are_denied(): void
    {
        [$class, $meeting, , $student] = $this->scenario();
        $otherClass = $this->activeClass();
        $otherMeeting = Meeting::factory()->active()->create(['school_class_id' => $otherClass->id, 'host_user_id' => $this->teacher($otherClass)->id]);
        $otherParticipant = MeetingParticipant::factory()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $this->student($otherClass)->id,
            'livekit_identity' => 'someone_else',
        ]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        // The request resolves the participant by (meeting, authenticated user), so
        // another user's participant is never reachable.
        $this->presenceState($rooms, $otherMeeting, $otherParticipant, MeetingProviderState::Ended);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $otherMeeting]))->assertNotFound();
        $this->assertSame(0, MeetingScreenShareRequest::query()->where('requester_user_id', $student->id)->count());
    }

    public function test_the_student_share_click_creates_one_pending_request_scoped_to_the_student(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.reference', $participant->screenShareRequests()->latest('id')->value('public_uuid'));

        $this->assertDatabaseHas('meeting_screen_share_requests', [
            'meeting_id' => $meeting->id,
            'meeting_participant_id' => $participant->id,
            'requester_user_id' => $student->id,
            'status' => MeetingScreenShareRequestStatus::Pending->value,
            'active_slot' => 1,
            'started_at' => null,
            'expires_at' => null,
            'decided_by' => null,
        ]);
    }

    public function test_repeated_share_clicks_reuse_the_active_request_and_grant_nothing(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $first = $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();
        $second = $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();

        $this->assertSame($first->json('request.reference'), $second->json('request.reference'));
        $this->assertSame(1, MeetingScreenShareRequest::query()->where('meeting_participant_id', $participant->id)->count());
        $this->assertNull($participant->screenShareRequests()->latest('id')->value('started_at'));
    }

    public function test_active_student_can_request_once_without_supplying_provider_identifiers(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();

        $first = $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]), [
            'livekit_identity' => 'attacker-controlled',
            'track_sid' => 'TR_attacker-controlled',
        ])->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonMissingPath('request.livekit_identity')
            ->assertJsonMissingPath('request.track_sid');

        $second = $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))
            ->assertCreated();

        $this->assertSame($first->json('request.reference'), $second->json('request.reference'));
        $this->assertSame(1, MeetingScreenShareRequest::query()->where('meeting_participant_id', $participant->id)->count());
    }

    public function test_assigned_teacher_can_approve_using_only_server_derived_room_and_identity(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()
            ->with($meeting->livekit_room_name, $participant->livekit_identity, true)
            ->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), [
            'decision' => 'approved',
            'livekit_identity' => 'ignored',
            'track_sid' => 'ignored',
        ])->assertOk()
            ->assertJsonPath('request.status', 'approved')
            ->assertJsonMissingPath('request.livekit_identity');

        $this->assertDatabaseHas('meeting_screen_share_requests', [
            'id' => $request->id,
            'status' => MeetingScreenShareRequestStatus::Approved->value,
            'decided_by' => $teacher->id,
            'active_slot' => 1,
        ]);
    }

    public function test_students_cannot_approve_requests_including_their_own(): void
    {
        [$class, $meeting, , $student] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $otherStudent = $this->student($class);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($student)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($otherStudent)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertForbidden();
    }

    public function test_cross_meeting_and_cross_class_request_bindings_are_rejected(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $otherClass = $this->activeClass();
        $otherMeeting = Meeting::factory()->active()->create(['school_class_id' => $otherClass->id, 'host_user_id' => $this->teacher($otherClass)->id]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $otherMeeting, $request]), ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$otherClass, $meeting, $request]), ['decision' => 'approved'])->assertNotFound();
    }

    public function test_removed_or_disconnected_student_cannot_request(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $participant->update(['removed_at' => now()]);
        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertForbidden();

        [$otherClass, $otherMeeting, , $otherStudent, $otherParticipant] = $this->scenario();
        // A closed row for the SID the provider still reports as connected is
        // contradictory evidence, so presence fails closed rather than reopening
        // attendance history.
        $otherParticipant->attendanceSessions()->update(['left_at' => now()]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $otherMeeting, $otherParticipant);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($otherStudent)->postJson(route('meetings.screen-share-requests.store', [$otherClass, $otherMeeting]))
            ->assertStatus(422)->assertJsonValidationErrors('screen_share');
    }

    public function test_rejection_never_grants_provider_permission(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'rejected'])
            ->assertOk()->assertJsonPath('request.status', 'rejected');
        $this->assertNull($request->fresh()->active_slot);
    }

    public function test_student_completion_revokes_permission_and_consumes_approval_idempotently(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        $this->actingAs($student)->deleteJson(route('meetings.screen-share-requests.destroy', [$class, $meeting, $request]))
            ->assertOk()->assertJsonPath('request.status', 'consumed');
        $this->actingAs($student)->deleteJson(route('meetings.screen-share-requests.destroy', [$class, $meeting, $request]))
            ->assertOk()->assertJsonPath('request.status', 'consumed');
        $this->assertNull($request->fresh()->active_slot);
    }

    public function test_host_request_queue_exposes_safe_display_data_only(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $request = $this->request($class, $meeting, $student);

        $this->actingAs($teacher)->getJson(route('meetings.screen-share-requests.index', [$class, $meeting]))
            ->assertOk()
            ->assertJsonPath('requests.0.reference', $request->public_uuid)
            ->assertJsonPath('requests.0.display_name', $student->name)
            ->assertJsonMissingPath('requests.0.livekit_identity')
            ->assertJsonMissingPath('requests.0.track_sid');
    }

    public function test_student_request_state_is_fresh_and_never_cacheable(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        $this->actingAs($student)->getJson(route('meetings.screen-share-requests.index', [$class, $meeting]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('current.reference', $request->public_uuid)
            ->assertJsonPath('current.status', 'approved');
    }

    public function test_screen_track_unpublish_webhook_revokes_permission_and_consumes_approval(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $published = LiveKitWebhookEvent::factory()->create([
            'event_type' => 'track_published',
            'livekit_room_name' => $meeting->livekit_room_name,
            'participant_identity' => $participant->livekit_identity,
            'track_source' => TrackSource::SCREEN_SHARE,
        ]);
        (new ProcessLiveKitWebhook($published->event_id))->handle($rooms, app(AuditLogger::class));
        $this->assertNotNull($request->fresh()->started_at);
        $this->assertNull($request->fresh()->expires_at);

        $event = LiveKitWebhookEvent::factory()->create([
            'event_type' => 'track_unpublished',
            'livekit_room_name' => $meeting->livekit_room_name,
            'participant_identity' => $participant->livekit_identity,
            'track_source' => TrackSource::SCREEN_SHARE,
        ]);

        (new ProcessLiveKitWebhook($event->event_id))->handle($rooms, app(AuditLogger::class));

        $this->assertSame(MeetingScreenShareRequestStatus::Consumed, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
    }

    public function test_participant_removal_invalidates_approval_and_removes_the_provider_participant(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('removeParticipant')->once()->with($meeting->livekit_room_name, $participant->livekit_identity)->andReturn(MeetingProviderState::Ended);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        $this->actingAs($teacher)->deleteJson(route('meetings.participants.destroy', [$class, $meeting, $participant]))->assertOk();

        $this->assertSame(MeetingScreenShareRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
    }

    public function test_meeting_end_invalidates_every_active_screen_share_approval(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $provider = new FakeMeetingLifecycleProvider;
        $this->app->instance(MeetingLifecycleProvider::class, $provider);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        $this->actingAs($teacher)->postJson(route('meetings.end', [$class, $meeting]))->assertOk();

        $this->assertSame(1, $provider->endCalls);
        $this->assertSame(MeetingScreenShareRequestStatus::Expired, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
    }

    public function test_unused_approval_expires_and_revokes_provider_permission(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $request->update(['expires_at' => now()->subSecond()]);
        // The approval really was never used: the provider authoritatively reports
        // no screen-share video, so the existing revoke and expire path applies.
        $this->notSharing($rooms, $meeting, $participant);

        (new ExpireMeetingScreenShareApproval($request->id))->handle($rooms, app(ReconcileMeetingScreenShareState::class));

        $this->assertSame(MeetingScreenShareRequestStatus::Expired, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
        $this->assertNull($request->fresh()->expires_at);
    }

    public function test_screen_track_published_converts_the_approval_into_an_active_share(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->assertNotNull($request->fresh()->expires_at);

        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);

        $started = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $started->status);
        $this->assertTrue($started->status->isSharing());
        $this->assertSame(1, $started->active_slot);
        $this->assertNotNull($started->started_at);
        $this->assertNull($started->expires_at);
    }

    public function test_a_delayed_expiry_job_cannot_terminate_a_share_that_already_started(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $queuedJob = new ExpireMeetingScreenShareApproval($request->id);
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $request->update(['expires_at' => now()->subSecond()]);

        // The job the approval queued before the share began must now be inert.
        // The provider still reports the live canonical video, so reconciliation
        // confirms Sharing and the job must neither revoke nor expire.
        $this->screenVideoPresent($rooms, $meeting, $participant);
        // Only the revoke is forbidden: the grant already happened once at
        // approval time and must not be re-asserted by this job.
        $rooms->shouldReceive('setParticipantScreenSharePermission')
            ->with($meeting->livekit_room_name, $participant->livekit_identity, false)
            ->never();
        $queuedJob->handle($rooms, app(ReconcileMeetingScreenShareState::class));

        $active = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $active->status);
        $this->assertSame(1, $active->active_slot);
        $this->assertNull($active->completed_at);
    }

    public function test_expiry_never_revokes_a_share_that_started_even_if_the_approval_row_was_never_converted(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        // started_at is the server-side proof that a share really began, so it
        // must stop the job even if the row is still sitting in Approved.
        $request->update(['started_at' => now()->subMinutes(30), 'expires_at' => now()->subSecond()]);

        // The job short-circuits on started_at before any provider I/O, so no
        // screenShareState call may happen and nothing may be revoked.
        $rooms->shouldNotReceive('screenShareState');
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        (new ExpireMeetingScreenShareApproval($request->id))->handle($rooms, app(ReconcileMeetingScreenShareState::class));

        $active = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $active->status);
        $this->assertSame(1, $active->active_slot);
        $this->assertNull($active->completed_at);
    }

    public function test_expiry_is_a_safe_no_op_once_a_request_left_the_active_state(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        foreach ([MeetingScreenShareRequestStatus::Rejected, MeetingScreenShareRequestStatus::Cancelled, MeetingScreenShareRequestStatus::Consumed, MeetingScreenShareRequestStatus::Expired] as $terminal) {
            $request = $this->request($class, $meeting, $student);
            $request->update(['status' => $terminal, 'active_slot' => null, 'completed_at' => now(), 'expires_at' => now()->subSecond()]);
            $rooms = Mockery::mock(LiveKitRoomManager::class);
            $rooms->shouldNotReceive('setParticipantScreenSharePermission');
            // A request that already left the active state is not a candidate, so
            // the job must short-circuit before asking the provider anything.
            $rooms->shouldNotReceive('screenShareState');

            (new ExpireMeetingScreenShareApproval($request->id))->handle(
                $rooms,
                app(ReconcileMeetingScreenShareState::class)
            );

            $this->assertSame($terminal, $request->fresh()->status);
            $this->assertNull($request->fresh()->active_slot);
        }
    }

    public function test_camera_and_microphone_track_events_never_move_the_screen_share_lifecycle(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission')->with($meeting->livekit_room_name, $participant->livekit_identity, false);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        foreach ([TrackSource::CAMERA, TrackSource::MICROPHONE] as $source) {
            $this->screenTrack($meeting, $participant, 'track_published', $source, $rooms);
            $this->screenTrack($meeting, $participant, 'track_unpublished', $source, $rooms);
        }

        $untouched = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $untouched->status);
        $this->assertSame(1, $untouched->active_slot);
        $this->assertNull($untouched->started_at);
    }

    public function test_screen_track_events_from_another_participant_never_touch_the_request(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission')->with($meeting->livekit_room_name, $participant->livekit_identity, false);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $otherParticipant = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $this->student($class)->id]);

        $this->screenTrack($meeting, $otherParticipant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $this->screenTrack($meeting, $otherParticipant, 'track_unpublished', TrackSource::SCREEN_SHARE, $rooms);

        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->active_slot);
    }

    public function test_repeated_stop_signals_consume_the_share_once_and_keep_permission_revoked(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE_AUDIO, $rooms);

        $this->screenTrack($meeting, $participant, 'track_unpublished', TrackSource::SCREEN_SHARE, $rooms);
        $this->screenTrack($meeting, $participant, 'track_unpublished', TrackSource::SCREEN_SHARE_AUDIO, $rooms);
        $this->actingAs($student)->deleteJson(route('meetings.screen-share-requests.destroy', [$class, $meeting, $request]))->assertOk();

        $this->assertSame(MeetingScreenShareRequestStatus::Consumed, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
        $this->assertNull($request->fresh()->expires_at);
    }

    public function test_the_next_share_after_a_completed_one_requires_a_fresh_request(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $this->screenTrack($meeting, $participant, 'track_unpublished', TrackSource::SCREEN_SHARE, $rooms);

        $second = $this->request($class, $meeting, $student);

        $this->assertNotSame($request->public_uuid, $second->public_uuid);
        $this->assertSame(MeetingScreenShareRequestStatus::Pending, $second->status);
        $this->assertSame(2, MeetingScreenShareRequest::query()->where('meeting_participant_id', $participant->id)->count());
        $this->assertSame(1, MeetingScreenShareRequest::query()->where('meeting_participant_id', $participant->id)->where('active_slot', 1)->count());
    }

    public function test_a_request_never_creates_a_second_row_while_a_share_is_active(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);

        $again = $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))->assertCreated();

        $this->assertSame($request->public_uuid, $again->json('request.reference'));
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $request->fresh()->status);
    }

    public function test_disconnect_revokes_an_active_share_and_preserves_the_rest_of_the_room(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $session = $participant->attendanceSessions()->whereNull('left_at')->firstOrFail();
        $left = LiveKitWebhookEvent::factory()->create([
            'event_type' => 'participant_left',
            'livekit_room_name' => $meeting->livekit_room_name,
            'participant_identity' => $participant->livekit_identity,
            'participant_sid' => $session->livekit_participant_sid,
        ]);

        (new ProcessLiveKitWebhook($left->event_id))->handle($rooms, app(AuditLogger::class));

        $this->assertSame(MeetingScreenShareRequestStatus::Consumed, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
        $this->assertNotNull($session->fresh()->left_at);
    }

    public function test_meeting_cancel_invalidates_an_active_screen_share(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        $meeting->update(['status' => MeetingStatus::Scheduled]);
        $this->app->instance(MeetingLifecycleProvider::class, new FakeMeetingLifecycleProvider);
        $this->actingAs($teacher)->patch(route('meetings.cancel', [$class, $meeting]))->assertRedirect();

        $this->assertSame(MeetingScreenShareRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
    }

    public function test_host_and_administrator_approve_while_an_unassigned_teacher_cannot(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $unassigned = $this->teacher($class, false);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($unassigned)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertForbidden();
        $this->assertSame(MeetingScreenShareRequestStatus::Pending, $request->fresh()->status);

        $administrator = $this->roleUser('Admin');
        $this->actingAs($administrator)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame($administrator->id, $request->fresh()->decided_by);
    }

    public function test_student_token_carries_camera_and_microphone_only_until_an_approval_exists(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $issuer = new FakeLiveKitTokenIssuer;
        $this->app->instance(LiveKitTokenIssuer::class, $issuer);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        MeetingJoinRequest::factory()->admitted()->create(['meeting_id' => $meeting->id, 'requester_user_id' => $student->id]);

        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
        $this->assertSame(['camera', 'microphone'], $issuer->publishSources);

        $request = $this->request($class, $meeting, $student);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
        $this->assertSame(['camera', 'microphone', 'screen_share', 'screen_share_audio'], $issuer->publishSources);

        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
        $this->assertSame(['camera', 'microphone', 'screen_share', 'screen_share_audio'], $issuer->publishSources);

        $this->screenTrack($meeting, $participant, 'track_unpublished', TrackSource::SCREEN_SHARE, $rooms);
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
        $this->assertSame(['camera', 'microphone'], $issuer->publishSources);
    }

    public function test_host_screen_sharing_needs_no_request_and_keeps_its_permission_free(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $issuer = new FakeLiveKitTokenIssuer;
        $this->app->instance(LiveKitTokenIssuer::class, $issuer);

        $this->actingAs($teacher)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();

        $this->assertSame(['camera', 'microphone', 'screen_share', 'screen_share_audio'], $issuer->publishSources);
        $this->assertSame(0, $meeting->screenShareRequests()->count());
        $this->actingAs($teacher)->getJson(route('meetings.screen-share-requests.index', [$class, $meeting]))
            ->assertOk()->assertJsonPath('requests', []);
    }

    // ------------------------------------------------------------------
    // Security matrix: reconciliation against authoritative provider state.
    // ------------------------------------------------------------------

    public function test_reconciliation_promotes_an_unused_approval_when_the_provider_confirms_a_canonical_video_before_the_window_lapses(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $approved = $request->fresh();
        $this->assertNotNull($approved->expires_at);
        $this->assertTrue($approved->expires_at->isFuture());

        $this->screenVideoPresent($rooms, $meeting, $participant);
        $outcome = app(ReconcileMeetingScreenShareState::class)->handle($meeting, $participant, $request->fresh());

        $this->assertSame(MeetingScreenShareReconciliation::SharingConfirmed, $outcome);
        $promoted = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $promoted->status);
        $this->assertNotNull($promoted->started_at);
        $this->assertNull($promoted->expires_at);
        $this->assertSame(1, $promoted->active_slot);
        $this->assertNull($promoted->completed_at);
        // The host's decision is the authorisation, so promotion must never
        // rewrite who approved it or when.
        $this->assertSame($teacher->id, $promoted->decided_by);
        $this->assertTrue($approved->decided_at->equalTo($promoted->decided_at));
    }

    public function test_reconciliation_is_an_idempotent_no_op_for_a_request_that_is_already_sharing(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $before = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $before->status);

        $this->screenVideoPresent($rooms, $meeting, $participant);
        $outcome = app(ReconcileMeetingScreenShareState::class)->handle($meeting, $participant, $request->fresh());

        $this->assertSame(MeetingScreenShareReconciliation::SharingConfirmed, $outcome);
        $after = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $after->status);
        // started_at is the proof the share began, so a second observer must not
        // restate it or regress the request.
        $this->assertTrue($before->started_at->equalTo($after->started_at));
        $this->assertSame(1, $after->active_slot);
        $this->assertNull($after->completed_at);
        $this->assertNull($after->expires_at);
    }

    public function test_reconciliation_never_resurrects_a_terminal_request_even_while_the_provider_reports_a_live_share(): void
    {
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        foreach ([MeetingScreenShareRequestStatus::Rejected, MeetingScreenShareRequestStatus::Cancelled, MeetingScreenShareRequestStatus::Consumed, MeetingScreenShareRequestStatus::Expired] as $terminal) {
            $request = $this->request($class, $meeting, $student);
            $request->update(['status' => $terminal, 'active_slot' => null, 'completed_at' => now()->subMinute(), 'expires_at' => null]);
            $before = $request->fresh();
            // The provider is asked before any status is considered, so a live
            // canonical video is deliberately available here.
            $this->screenVideoPresent($rooms, $meeting, $participant);
            $outcome = app(ReconcileMeetingScreenShareState::class)->handle($meeting, $participant, $request->fresh());
            // Reported as already settled rather than as a new share. The
            // security property is that not one field of a terminal row moves.
            $this->assertSame(MeetingScreenShareReconciliation::SharingConfirmed, $outcome);

            $after = $request->fresh();
            $this->assertSame($terminal, $after->status);
            $this->assertNull($after->active_slot);
            $this->assertNull($after->started_at);
            $this->assertTrue($before->completed_at->equalTo($after->completed_at));
        }
    }

    public function test_reconciliation_reports_an_unreachable_provider_and_mutates_nothing(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        $this->providerUnknown($rooms, $meeting, $participant);
        $outcome = app(ReconcileMeetingScreenShareState::class)->handle($meeting, $participant, $request->fresh());

        $this->assertSame(MeetingScreenShareReconciliation::ProviderUnreachable, $outcome);
        // Silence is not absence: an approval must never lapse because the
        // provider could not be asked.
        $untouched = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $untouched->status);
        $this->assertSame(1, $untouched->active_slot);
        $this->assertNull($untouched->started_at);
        $this->assertNull($untouched->completed_at);
        $this->assertNotNull($untouched->expires_at);
    }

    public function test_reconciliation_reports_definitely_not_sharing_for_an_active_participant_with_no_screen_video(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        $this->notSharing($rooms, $meeting, $participant);
        $outcome = app(ReconcileMeetingScreenShareState::class)->handle($meeting, $participant, $request->fresh());

        $this->assertSame(MeetingScreenShareReconciliation::DefinitelyNotSharing, $outcome);
        $unpromoted = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $unpromoted->status);
        $this->assertNull($unpromoted->started_at);
        $this->assertSame(1, $unpromoted->active_slot);
        $this->assertNull($unpromoted->completed_at);
    }

    public function test_reconciliation_never_promotes_a_screen_share_audio_only_publication(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        // The provider publishes only the audio companion. Audio can outlive or
        // precede its video, so it is never proof that a share began.
        $this->audioOnly($rooms, $meeting, $participant);
        $outcome = app(ReconcileMeetingScreenShareState::class)->handle($meeting, $participant, $request->fresh());

        $this->assertSame(MeetingScreenShareReconciliation::DefinitelyNotSharing, $outcome);
        $this->assertNotSame(MeetingScreenShareReconciliation::SharingConfirmed, $outcome);
        $unpromoted = $request->fresh();
        $this->assertNotSame(MeetingScreenShareRequestStatus::Sharing, $unpromoted->status);
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $unpromoted->status);
        $this->assertNull($unpromoted->started_at);
        $this->assertSame(1, $unpromoted->active_slot);
    }

    public function test_reconciliation_never_promotes_a_publication_first_seen_after_the_approval_window_lapsed(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $lapsedAt = now()->subSecond();
        $request->update(['expires_at' => $lapsedAt]);
        // The deadline is compared as persisted, because the column keeps second
        // precision and the in-memory microseconds never survive the write.
        $lapsedAt = $request->fresh()->expires_at;

        // A real canonical video IS published, but after the host's window
        // closed. Turning that into a share would silently turn a deadline into
        // a suggestion, so it must be reported for containment instead.
        $this->screenVideoPresent($rooms, $meeting, $participant);
        $reconcile = app(ReconcileMeetingScreenShareState::class);
        $outcome = $reconcile->handle($meeting, $participant, $request->fresh());

        $this->assertSame(MeetingScreenShareReconciliation::LapsedLivePublication, $outcome);
        $this->assertNotSame(MeetingScreenShareReconciliation::SharingConfirmed, $outcome);
        // The provider's own SID is surfaced for containment and is never taken
        // from a client.
        $this->assertSame('TR_provider_screen_video', $reconcile->observedVideoTrackSid);
        $unpromoted = $request->fresh();
        $this->assertNotSame(MeetingScreenShareRequestStatus::Sharing, $unpromoted->status);
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $unpromoted->status);
        $this->assertNull($unpromoted->started_at);
        $this->assertSame(1, $unpromoted->active_slot);
        $this->assertNull($unpromoted->completed_at);
        $this->assertTrue($lapsedAt->equalTo($unpromoted->expires_at));
    }

    // ------------------------------------------------------------------
    // Security matrix: the expiry job's containment behaviour.
    // ------------------------------------------------------------------

    public function test_the_expiry_job_asks_the_provider_nothing_before_the_approval_window_lapses(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        // The window is still open, so the job may not inspect, revoke or mute.
        $rooms->shouldNotReceive('screenShareState');
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        $rooms->shouldNotReceive('mutePublishedTrack');
        (new ExpireMeetingScreenShareApproval($request->id))->handle($rooms, app(ReconcileMeetingScreenShareState::class));

        $untouched = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $untouched->status);
        $this->assertSame(1, $untouched->active_slot);
        $this->assertNull($untouched->started_at);
        $this->assertNull($untouched->completed_at);
        $this->assertNotNull($untouched->expires_at);
    }

    public function test_the_expiry_job_expires_and_revokes_when_the_provider_definitively_reports_no_share(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $request->update(['expires_at' => now()->subSecond()]);
        $this->notSharing($rooms, $meeting, $participant);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        // There is no published track, so there is nothing to contain.
        $rooms->shouldNotReceive('mutePublishedTrack');

        (new ExpireMeetingScreenShareApproval($request->id))->handle($rooms, app(ReconcileMeetingScreenShareState::class));

        $expired = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Expired, $expired->status);
        $this->assertNull($expired->active_slot);
        $this->assertNotNull($expired->completed_at);
        $this->assertNull($expired->expires_at);
        $this->assertNull($expired->started_at);
    }

    public function test_the_expiry_job_retries_without_revoking_when_the_provider_cannot_be_reached(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $lapsedAt = now()->subSecond();
        $request->update(['expires_at' => $lapsedAt]);
        // The deadline is compared as persisted, because the column keeps second
        // precision and the in-memory microseconds never survive the write.
        $lapsedAt = $request->fresh()->expires_at;
        $this->providerUnknown($rooms, $meeting, $participant);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        $rooms->shouldNotReceive('mutePublishedTrack');

        try {
            (new ExpireMeetingScreenShareApproval($request->id))->handle($rooms, app(ReconcileMeetingScreenShareState::class));
            $this->fail('An unreachable provider must leave the expiry retryable.');
        } catch (RuntimeException $failure) {
            $this->assertSame('Screen sharing approval state could not be confirmed; expiry is pending retry.', $failure->getMessage());
        }

        // No verdict was reached, so the approval stands untouched.
        $stillApproved = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $stillApproved->status);
        $this->assertSame(1, $stillApproved->active_slot);
        $this->assertNull($stillApproved->started_at);
        $this->assertNull($stillApproved->completed_at);
        $this->assertTrue($lapsedAt->equalTo($stillApproved->expires_at));
    }

    public function test_the_expiry_job_contains_a_lapsed_publication_with_the_providers_own_track_sid(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $request->update(['expires_at' => now()->subSecond()]);
        $this->screenVideoPresent($rooms, $meeting, $participant, 'TR_provider_screen_video');
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        // Containment mutes the track the provider itself reported, because
        // LiveKit has no operation that unpublishes it.
        $rooms->shouldReceive('mutePublishedTrack')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, 'TR_provider_screen_video')->andReturn(MeetingProviderState::Active);

        (new ExpireMeetingScreenShareApproval($request->id))->handle($rooms, app(ReconcileMeetingScreenShareState::class));

        $expired = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Expired, $expired->status);
        $this->assertNull($expired->active_slot);
        $this->assertNotNull($expired->completed_at);
        $this->assertNull($expired->expires_at);
        // The publication was never legitimised, only contained.
        $this->assertNull($expired->started_at);
    }

    public function test_the_expiry_job_never_completes_a_lapsed_request_it_could_not_contain(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $lapsedAt = now()->subSecond();
        $request->update(['expires_at' => $lapsedAt]);
        // The deadline is compared as persisted, because the column keeps second
        // precision and the in-memory microseconds never survive the write.
        $lapsedAt = $request->fresh()->expires_at;
        $this->screenVideoPresent($rooms, $meeting, $participant, 'TR_provider_screen_video');
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        // The grant is withdrawn, but the track is still live and the provider
        // cannot confirm the mute.
        $rooms->shouldReceive('mutePublishedTrack')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, 'TR_provider_screen_video')->andReturn(MeetingProviderState::Unknown);

        try {
            (new ExpireMeetingScreenShareApproval($request->id))->handle($rooms, app(ReconcileMeetingScreenShareState::class));
            $this->fail('An unconfirmed mute must leave the expiry retryable.');
        } catch (RuntimeException $failure) {
            $this->assertSame('Lapsed screen sharing approval is still published; mute is pending retry.', $failure->getMessage());
        }

        // The precise safe state: the row is NOT terminally completed, so the
        // queue retries the containment instead of recording a success that
        // never happened. The provider grant stays withdrawn.
        $pending = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $pending->status);
        $this->assertNotSame(MeetingScreenShareRequestStatus::Expired, $pending->status);
        $this->assertSame(1, $pending->active_slot);
        $this->assertNull($pending->started_at);
        $this->assertNull($pending->completed_at);
        $this->assertTrue($lapsedAt->equalTo($pending->expires_at));
    }

    public function test_the_expiry_job_cannot_expire_or_revoke_a_share_that_is_already_live(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $live = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $live->status);
        // A re-delivered job whose delay elapsed must still be inert.
        $request->update(['expires_at' => now()->subSecond()]);
        $rooms->shouldNotReceive('screenShareState');
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        $rooms->shouldNotReceive('mutePublishedTrack');

        (new ExpireMeetingScreenShareApproval($request->id))->handle($rooms, app(ReconcileMeetingScreenShareState::class));

        $unchanged = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $unchanged->status);
        $this->assertSame(1, $unchanged->active_slot);
        $this->assertNull($unchanged->completed_at);
        $this->assertTrue($live->started_at->equalTo($unchanged->started_at));
    }

    // ------------------------------------------------------------------
    // Security matrix: webhook and reconciliation observers racing.
    // ------------------------------------------------------------------

    public function test_a_publication_webhook_arriving_after_a_reconciliation_does_not_restart_the_share(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        $this->screenVideoPresent($rooms, $meeting, $participant);
        app(ReconcileMeetingScreenShareState::class)->handle($meeting, $participant, $request->fresh());
        $reconciled = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $reconciled->status);

        // The canonical publication webhook now catches up with the same share.
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);

        $after = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $after->status);
        $this->assertSame(1, $after->active_slot);
        $this->assertNull($after->completed_at);
        // Two observers agreeing must produce one transition, not a restarted
        // start time.
        $this->assertTrue($reconciled->started_at->equalTo($after->started_at));
    }

    public function test_a_reconciliation_arriving_after_the_publication_webhook_does_not_regress_the_share(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $webhook = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $webhook->status);

        $this->screenVideoPresent($rooms, $meeting, $participant);
        $outcome = app(ReconcileMeetingScreenShareState::class)->handle($meeting, $participant, $request->fresh());

        $this->assertSame(MeetingScreenShareReconciliation::SharingConfirmed, $outcome);
        $after = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $after->status);
        $this->assertSame(1, $after->active_slot);
        $this->assertNull($after->completed_at);
        $this->assertNull($after->expires_at);
        $this->assertTrue($webhook->started_at->equalTo($after->started_at));
    }

    public function test_neither_a_late_publication_webhook_nor_the_expiry_job_can_end_a_reconciled_share(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        $this->screenVideoPresent($rooms, $meeting, $participant);
        app(ReconcileMeetingScreenShareState::class)->handle($meeting, $participant, $request->fresh());
        $reconciled = $request->fresh();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $request->update(['expires_at' => now()->subSecond()]);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission');
        $rooms->shouldNotReceive('mutePublishedTrack');

        (new ExpireMeetingScreenShareApproval($request->id))->handle($rooms, app(ReconcileMeetingScreenShareState::class));

        $live = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $live->status);
        $this->assertSame(1, $live->active_slot);
        $this->assertNull($live->completed_at);
        $this->assertTrue($reconciled->started_at->equalTo($live->started_at));
    }

    // ------------------------------------------------------------------
    // Security matrix: authorization of the student reconcile endpoint.
    // ------------------------------------------------------------------

    public function test_only_the_owning_student_can_reconcile_their_own_approved_request(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenVideoPresent($rooms, $meeting, $participant);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $request]))
            ->assertOk()
            ->assertExactJson(['reconciled' => true, 'retrying' => false]);

        // A confirmed share needs no retry, and the payload carries no provider
        // identifier of any kind.
        Queue::assertNotPushed(ReconcileMeetingScreenSharePublication::class);
        $promoted = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $promoted->status);
        $this->assertNotNull($promoted->started_at);
        $this->assertSame(1, $promoted->active_slot);
    }

    public function test_reconciling_an_already_sharing_request_is_allowed_and_idempotent(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $before = $request->fresh();
        $this->screenVideoPresent($rooms, $meeting, $participant);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $request]))
            ->assertOk()
            ->assertExactJson(['reconciled' => true, 'retrying' => false]);

        Queue::assertNotPushed(ReconcileMeetingScreenSharePublication::class);
        $after = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $after->status);
        $this->assertSame(1, $after->active_slot);
        $this->assertTrue($before->started_at->equalTo($after->started_at));
    }

    public function test_a_student_cannot_reconcile_another_students_request(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $otherStudent = $this->student($class);
        $otherParticipant = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $otherStudent->id]);
        $otherRequest = $this->request($class, $meeting, $otherStudent);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $otherParticipant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $otherRequest]), ['decision' => 'approved'])->assertOk();
        // Possession of the public reference is not authorisation.
        $rooms->shouldNotReceive('screenShareState');

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $otherRequest]))
            ->assertForbidden();

        Queue::assertNotPushed(ReconcileMeetingScreenSharePublication::class);
        $untouched = $otherRequest->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $untouched->status);
        $this->assertSame(1, $untouched->active_slot);
        $this->assertNull($untouched->started_at);
    }

    public function test_a_request_reference_cannot_be_reconciled_through_another_meeting(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $otherMeeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);
        $rooms->shouldNotReceive('screenShareState');

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $otherMeeting, $request]))
            ->assertNotFound();

        Queue::assertNotPushed(ReconcileMeetingScreenSharePublication::class);
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $request->fresh()->status);
        $this->assertNull($request->fresh()->started_at);
    }

    public function test_a_removed_student_cannot_reconcile_their_own_request(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $participant->update(['removed_at' => now()]);
        $rooms->shouldNotReceive('screenShareState');

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $request]))
            ->assertForbidden();

        Queue::assertNotPushed(ReconcileMeetingScreenSharePublication::class);
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $request->fresh()->status);
        $this->assertNull($request->fresh()->started_at);
    }

    public function test_a_host_and_a_super_administrator_cannot_drive_the_student_reconcile_endpoint(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $administrator = $this->roleUser('Admin');
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $rooms->shouldNotReceive('screenShareState');

        // Neither has a participant row in this meeting, so the endpoint cannot
        // resolve the participant it would have to act on and reveals nothing.
        $this->actingAs($teacher)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $request]))
            ->assertNotFound();
        $this->actingAs($administrator)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $request]))
            ->assertNotFound();

        Queue::assertNotPushed(ReconcileMeetingScreenSharePublication::class);
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $request->fresh()->status);
        $this->assertNull($request->fresh()->started_at);
    }

    public function test_the_role_gate_denies_reconciliation_even_to_a_caller_who_has_a_participant_row(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        MeetingParticipant::factory()->host()->create(['meeting_id' => $meeting->id, 'user_id' => $teacher->id]);
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        // The participant now resolves, so the policy itself must refuse.
        $rooms->shouldNotReceive('screenShareState');

        $this->actingAs($teacher)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $request]))
            ->assertForbidden();

        Queue::assertNotPushed(ReconcileMeetingScreenSharePublication::class);
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $request->fresh()->status);
        $this->assertNull($request->fresh()->started_at);
    }

    public function test_a_terminal_request_cannot_be_reconciled_back_into_an_approval_in_flight(): void
    {
        Queue::fake();
        [$class, $meeting, , $student, $participant] = $this->scenario();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $this->presentNow($rooms, $meeting, $participant);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        foreach ([MeetingScreenShareRequestStatus::Rejected, MeetingScreenShareRequestStatus::Cancelled, MeetingScreenShareRequestStatus::Consumed, MeetingScreenShareRequestStatus::Expired] as $terminal) {
            $request = $this->request($class, $meeting, $student);
            $request->update(['status' => $terminal, 'active_slot' => null, 'completed_at' => now(), 'expires_at' => null]);

            $this->actingAs($student)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $request]))
                ->assertForbidden();

            $this->assertSame($terminal, $request->fresh()->status);
            $this->assertNull($request->fresh()->active_slot);
            $this->assertNull($request->fresh()->started_at);
        }

        Queue::assertNotPushed(ReconcileMeetingScreenSharePublication::class);
        $rooms->shouldNotReceive('screenShareState');
    }

    // ------------------------------------------------------------------
    // Security matrix: server-derived provider identity on the endpoint.
    // ------------------------------------------------------------------

    public function test_the_reconcile_endpoint_ignores_every_provider_identifier_a_browser_could_send(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        // screenVideoPresent asserts the provider is asked with exactly the
        // server-derived room name and identity, so an attacker-chosen pair
        // would fail this expectation rather than be honoured.
        $this->screenVideoPresent($rooms, $meeting, $participant);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $request]), [
            'room' => 'attacker-room',
            'livekit_room_name' => 'attacker-room',
            'identity' => 'attacker-identity',
            'livekit_identity' => 'attacker-identity',
            'participant_sid' => 'PA_attacker',
            'track_sid' => 'TR_attacker',
            'track_source' => 1,
        ])->assertOk()->assertExactJson(['reconciled' => true, 'retrying' => false]);

        Queue::assertNotPushed(ReconcileMeetingScreenSharePublication::class);
        $promoted = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $promoted->status);
        $this->assertNotNull($promoted->started_at);
        $this->assertSame(1, $promoted->active_slot);
    }

    public function test_a_reconcile_the_provider_cannot_answer_hands_off_to_the_bounded_retry(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->providerUnknown($rooms, $meeting, $participant);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $request]))
            ->assertStatus(202)
            ->assertExactJson(['reconciled' => false, 'retrying' => true]);

        Queue::assertPushed(ReconcileMeetingScreenSharePublication::class, fn ($job) => $job->requestId === $request->id);
        $untouched = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $untouched->status);
        $this->assertSame(1, $untouched->active_slot);
        $this->assertNull($untouched->started_at);
        $this->assertNull($untouched->completed_at);
    }

    public function test_the_reconcile_endpoint_never_reports_a_lapsed_publication_as_retrying(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $lapsedAt = now()->subSecond();
        $request->update(['expires_at' => $lapsedAt]);
        // The deadline is compared as persisted, because the column keeps second
        // precision and the in-memory microseconds never survive the write.
        $lapsedAt = $request->fresh()->expires_at;
        $this->screenVideoPresent($rooms, $meeting, $participant);

        $this->actingAs($student)->postJson(route('meetings.screen-share-requests.reconcile', [$class, $meeting, $request]))
            ->assertOk()
            ->assertExactJson(['reconciled' => false, 'retrying' => false]);

        // Containment is owned by the expiry job, so no retry is dispatched and
        // the unauthorised publication is never reported as reconciled.
        Queue::assertNotPushed(ReconcileMeetingScreenSharePublication::class);
        $unpromoted = $request->fresh();
        $this->assertNotSame(MeetingScreenShareRequestStatus::Sharing, $unpromoted->status);
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $unpromoted->status);
        $this->assertNull($unpromoted->started_at);
        $this->assertTrue($lapsedAt->equalTo($unpromoted->expires_at));
    }

    private function screenTrack(Meeting $meeting, MeetingParticipant $participant, string $type, int $source, LiveKitRoomManager $rooms): void
    {
        $event = LiveKitWebhookEvent::factory()->create([
            'event_type' => $type,
            'livekit_room_name' => $meeting->livekit_room_name,
            'participant_identity' => $participant->livekit_identity,
            'track_source' => $source,
            'track_sid' => 'TR_'.Str::random(20),
        ]);

        (new ProcessLiveKitWebhook($event->event_id))->handle($rooms, app(AuditLogger::class));
    }

    public function test_screen_audio_unpublish_does_not_end_a_session_whose_video_is_still_live(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        // A second revoke would mean the audio track ended the live session.
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE_AUDIO, $rooms);

        $this->screenTrack($meeting, $participant, 'track_unpublished', TrackSource::SCREEN_SHARE_AUDIO, $rooms);

        $live = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $live->status);
        $this->assertSame(1, $live->active_slot);
        $this->assertNotNull($live->started_at);

        $this->screenTrack($meeting, $participant, 'track_unpublished', TrackSource::SCREEN_SHARE, $rooms);

        $this->assertSame(MeetingScreenShareRequestStatus::Consumed, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
    }

    public function test_screen_audio_never_promotes_or_terminates_a_request_on_its_own(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission')->with($meeting->livekit_room_name, $participant->livekit_identity, false);
        $rooms->shouldNotReceive('mutePublishedTrack');
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();

        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE_AUDIO, $rooms);

        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $request->fresh()->status);
        $this->assertNull($request->fresh()->started_at);
        $this->assertSame(1, $request->fresh()->active_slot);
    }

    public function test_screen_audio_unpublish_after_video_is_a_no_op(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE_AUDIO, $rooms);
        $this->screenTrack($meeting, $participant, 'track_unpublished', TrackSource::SCREEN_SHARE, $rooms);

        $this->screenTrack($meeting, $participant, 'track_unpublished', TrackSource::SCREEN_SHARE_AUDIO, $rooms);
        $this->actingAs($student)->deleteJson(route('meetings.screen-share-requests.destroy', [$class, $meeting, $request]))->assertOk();

        $this->assertSame(MeetingScreenShareRequestStatus::Consumed, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
    }

    public function test_a_publication_after_the_approval_lapsed_is_expired_revoced_and_muted_by_its_verified_sid(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('mutePublishedTrack')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, 'TR_verified_screen')->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        // The queued expiry job never ran; the approval lapsed silently.
        $request->update(['expires_at' => now()->subSecond()]);
        $event = LiveKitWebhookEvent::factory()->create([
            'event_type' => 'track_published',
            'livekit_room_name' => $meeting->livekit_room_name,
            'participant_identity' => $participant->livekit_identity,
            'track_source' => TrackSource::SCREEN_SHARE,
            'track_sid' => 'TR_verified_screen',
        ]);

        (new ProcessLiveKitWebhook($event->event_id))->handle($rooms, app(AuditLogger::class));

        $expired = $request->fresh();
        $this->assertSame(MeetingScreenShareRequestStatus::Expired, $expired->status);
        $this->assertNull($expired->active_slot);
        $this->assertNull($expired->started_at);
        $this->assertNull($expired->expires_at);
        $this->assertNotNull($expired->completed_at);
    }

    public function test_a_lapsed_publication_is_still_terminated_when_the_track_cannot_be_muted(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('mutePublishedTrack')->once()->andReturn(MeetingProviderState::Unknown);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $request->update(['expires_at' => now()->subSecond()]);
        $this->screenTrack($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, $rooms);

        $this->assertSame(MeetingScreenShareRequestStatus::Expired, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
    }

    public function test_a_provider_grant_that_survives_a_failed_approval_is_withdrawn(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        // The audit sink fails once the grant has already been handed out.
        $this->app->instance(AuditLogger::class, Mockery::mock(AuditLogger::class)
            ->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit sink is unavailable.'))->getMock());

        try {
            app(DecideMeetingScreenShareRequest::class)->handle($teacher, $request, MeetingScreenShareRequestStatus::Approved);
            $this->fail('The approval failure should have been rethrown.');
        } catch (RuntimeException $failure) {
            $this->assertSame('Audit sink is unavailable.', $failure->getMessage());
        }

        $this->assertSame(MeetingScreenShareRequestStatus::Pending, $request->fresh()->status);
        $this->assertNull($request->fresh()->decided_at);
        $this->assertNull($request->fresh()->expires_at);
    }

    public function test_a_failed_provider_grant_never_marks_the_request_approved(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Unknown);
        $rooms->shouldNotReceive('setParticipantScreenSharePermission')->with($meeting->livekit_room_name, $participant->livekit_identity, false);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])
            ->assertStatus(422)->assertJsonValidationErrors('screen_share');

        $this->assertSame(MeetingScreenShareRequestStatus::Pending, $request->fresh()->status);
        $this->assertNull($request->fresh()->decided_at);
    }

    public function test_duplicate_webhook_delivery_runs_one_lifecycle_transition(): void
    {
        [$class, $meeting, $teacher, $student, $participant] = $this->scenario();
        $request = $this->request($class, $meeting, $student);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, true)->andReturn(MeetingProviderState::Active);
        $rooms->shouldReceive('setParticipantScreenSharePermission')->once()->with($meeting->livekit_room_name, $participant->livekit_identity, false)->andReturn(MeetingProviderState::Active);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($teacher)->patchJson(route('meetings.screen-share-requests.update', [$class, $meeting, $request]), ['decision' => 'approved'])->assertOk();
        $published = $this->signedScreenPayload($meeting, $participant, 'track_published', TrackSource::SCREEN_SHARE, 'TR_once', 'EV_duplicated');
        $unpublished = $this->signedScreenPayload($meeting, $participant, 'track_unpublished', TrackSource::SCREEN_SHARE, 'TR_once', 'EV_duplicated_stop');

        $this->postWebhook($published)->assertOk();
        $this->postWebhook($published)->assertOk();
        $this->assertSame(1, LiveKitWebhookEvent::query()->where('event_id', 'EV_duplicated')->count());
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $request->fresh()->status);

        $this->postWebhook($unpublished)->assertOk();
        $this->postWebhook($unpublished)->assertOk();

        $this->assertSame(1, LiveKitWebhookEvent::query()->where('event_id', 'EV_duplicated_stop')->count());
        $this->assertSame(MeetingScreenShareRequestStatus::Consumed, $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
    }

    private function signedScreenPayload(Meeting $meeting, MeetingParticipant $participant, string $event, int $source, string $trackSid, string $eventId): array
    {
        return [
            'event' => $event,
            'id' => $eventId,
            'createdAt' => now()->timestamp,
            'room' => ['name' => $meeting->livekit_room_name],
            'participant' => ['identity' => $participant->livekit_identity, 'sid' => 'PA_screen'],
            'track' => ['sid' => $trackSid, 'source' => $source, 'type' => 1],
        ];
    }

    private function postWebhook(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $token = (new AccessToken('test-key', 'test-secret-that-is-at-least-32-bytes'))
            ->setGrant(new VideoGrant)->setSha256(base64_encode(hash('sha256', $body, true)))->toJwt();

        return $this->call('POST', route('integrations.livekit.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], $body);
    }

    private function request(SchoolClass $class, Meeting $meeting, User $student): MeetingScreenShareRequest
    {
        $reference = $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))
            ->assertCreated()->json('request.reference');

        return MeetingScreenShareRequest::query()->where('public_uuid', $reference)->firstOrFail();
    }

    private function scenario(): array
    {
        $class = $this->activeClass();
        $teacher = $this->teacher($class);
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);
        $participant = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $student->id]);
        // The attendance row the participant_joined webhook would have opened for
        // the connection the provider currently reports.
        MeetingAttendanceSession::factory()->create([
            'meeting_participant_id' => $participant->id,
            'livekit_participant_sid' => self::CURRENT_SID,
            'left_at' => null,
        ]);

        return [$class, $meeting, $teacher, $student, $participant];
    }

    private function activeClass(): SchoolClass
    {
        $year = AcademicYear::query()->where('active_slot', 1)->first() ?? AcademicYear::factory()->active()->create();

        return SchoolClass::factory()->create(['academic_year_id' => $year->id, 'status' => SchoolClassStatus::Active]);
    }

    private function teacher(SchoolClass $class, bool $assigned = true): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        if ($assigned) {
            TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id]);
        }

        return $user;
    }

    private function student(SchoolClass $class): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create(['student_profile_id' => $profile->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id]);

        return $user;
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
