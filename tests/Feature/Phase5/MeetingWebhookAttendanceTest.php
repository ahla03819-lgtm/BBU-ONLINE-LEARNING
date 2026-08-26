<?php

namespace Tests\Feature\Phase5;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\VideoGrant;
use App\Enums\LiveKitWebhookStatus;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\LiveKitWebhookEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Services\LiveKit\LiveKitRoomManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MeetingWebhookAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['livekit.api_key' => 'test-key', 'livekit.api_secret' => 'test-secret-that-is-at-least-32-bytes']);
    }

    public function test_signed_join_and_leave_create_and_close_one_attendance_session_idempotently(): void
    {
        [$meeting, $participant] = $this->meetingParticipant();
        $participant->update(['join_reserved_until' => now()->addMinutes(5)]);
        $join = $this->payload('participant_joined', $meeting, $participant, 'PA_one');

        $this->postWebhook($join)->assertOk();
        $this->postWebhook($join)->assertOk();
        $participant->refresh();
        $this->assertNull($participant->join_reserved_until);
        $this->assertNotNull($participant->first_joined_at);
        $this->assertSame(1, MeetingAttendanceSession::query()->count());

        $leave = $this->payload('participant_left', $meeting, $participant, 'PA_one', now()->addMinute()->timestamp);
        $this->postWebhook($leave)->assertOk();
        $this->postWebhook($leave)->assertOk();
        $this->assertNotNull(MeetingAttendanceSession::query()->first()->left_at);
        $this->assertNotNull($participant->fresh()->last_left_at);
        $this->assertSame(1, MeetingAttendanceSession::query()->count());
    }

    public function test_leave_before_join_remains_pending_then_reconciliation_completes_it(): void
    {
        [$meeting, $participant] = $this->meetingParticipant();
        $leave = $this->payload('participant_left', $meeting, $participant, 'PA_late', now()->addMinute()->timestamp);
        $this->postWebhook($leave)->assertOk();
        $this->assertDatabaseHas('livekit_webhook_events', ['event_id' => $leave['id'], 'status' => LiveKitWebhookStatus::Pending->value]);

        $join = $this->payload('participant_joined', $meeting, $participant, 'PA_late');
        $this->postWebhook($join)->assertOk();
        $this->travel(2)->minutes();
        $this->artisan('meetings:reconcile-webhooks')->assertSuccessful();
        $this->assertDatabaseHas('livekit_webhook_events', ['event_id' => $leave['id'], 'status' => LiveKitWebhookStatus::Processed->value]);
        $this->assertDatabaseHas('meeting_attendance_sessions', ['livekit_participant_sid' => 'PA_late', 'leave_webhook_event_id' => $leave['id']]);
    }

    public function test_true_leave_and_rejoin_preserve_two_sessions(): void
    {
        [$meeting, $participant] = $this->meetingParticipant();
        $this->postWebhook($this->payload('participant_joined', $meeting, $participant, 'PA_first'));
        $this->postWebhook($this->payload('participant_left', $meeting, $participant, 'PA_first', now()->addMinute()->timestamp));
        $this->postWebhook($this->payload('participant_joined', $meeting, $participant, 'PA_second', now()->addMinutes(2)->timestamp));
        $this->assertSame(2, MeetingAttendanceSession::query()->count());
    }

    public function test_room_finished_closes_presence_and_meeting_without_reviving_terminal_states(): void
    {
        [$meeting, $participant] = $this->meetingParticipant();
        $this->postWebhook($this->payload('participant_joined', $meeting, $participant, 'PA_open'));
        $finish = $this->payload('room_finished', $meeting, null, null, now()->addHour()->timestamp);
        $this->postWebhook($finish)->assertOk();
        $this->assertSame(MeetingStatus::Ended, $meeting->fresh()->status);
        $this->assertNotNull(MeetingAttendanceSession::query()->first()->left_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.provider-finished']);

        $cancelled = Meeting::factory()->create(['school_class_id' => $meeting->school_class_id, 'status' => MeetingStatus::Cancelled]);
        $this->postWebhook($this->payload('room_finished', $cancelled))->assertOk();
        $this->assertSame(MeetingStatus::Cancelled, $cancelled->fresh()->status);
    }

    public function test_unknown_and_unsupported_verified_events_are_safely_ignored(): void
    {
        [$meeting, $participant] = $this->meetingParticipant();
        $unknown = $this->payload('participant_joined', $meeting, $participant, 'PA_unknown');
        $unknown['participant']['identity'] = (string) Str::uuid();
        $this->postWebhook($unknown)->assertOk();
        $unsupported = $this->payload('track_published', $meeting, $participant, 'PA_unknown');
        $this->postWebhook($unsupported)->assertOk();
        $this->assertSame(2, LiveKitWebhookEvent::query()->where('status', LiveKitWebhookStatus::Ignored)->count());
    }

    public function test_webhook_security_rejects_missing_bad_and_modified_requests_without_persisting_secrets(): void
    {
        [$meeting, $participant] = $this->meetingParticipant();
        $payload = $this->payload('participant_joined', $meeting, $participant, 'PA_secure');
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->call('POST', route('integrations.livekit.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertUnauthorized();
        $this->call('POST', route('integrations.livekit.webhook'), [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer bad'], $body)->assertStatus(415);
        $this->call('POST', route('integrations.livekit.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer bad'], $body)->assertUnauthorized();
        $this->assertDatabaseCount('livekit_webhook_events', 0);
    }

    public function test_removed_participant_join_is_rejected_at_provider_without_attendance(): void
    {
        [$meeting, $participant] = $this->meetingParticipant();
        $participant->update(['removed_at' => now()]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('removeParticipant')->once()->with($meeting->livekit_room_name, $participant->livekit_identity)->andReturn(MeetingProviderState::Ended);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->postWebhook($this->payload('participant_joined', $meeting, $participant, 'PA_removed'))->assertOk();
        $this->assertDatabaseCount('meeting_attendance_sessions', 0);
        $this->assertNotNull($participant->fresh()->removed_at);
    }

    private function meetingParticipant(): array
    {
        $class = SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active]);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        $participant = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id]);

        return [$meeting, $participant];
    }

    private function payload(string $event, Meeting $meeting, ?MeetingParticipant $participant = null, ?string $sid = null, ?int $at = null): array
    {
        return array_filter([
            'event' => $event, 'id' => (string) Str::uuid(), 'createdAt' => $at ?? now()->timestamp,
            'room' => ['name' => $meeting->livekit_room_name],
            'participant' => $participant ? ['identity' => $participant->livekit_identity, 'sid' => $sid] : null,
        ], fn ($value) => $value !== null);
    }

    private function postWebhook(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $token = (new AccessToken('test-key', 'test-secret-that-is-at-least-32-bytes'))->setGrant(new VideoGrant)->setSha256(base64_encode(hash('sha256', $body, true)))->toJwt();

        return $this->call('POST', route('integrations.livekit.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], $body);
    }
}
