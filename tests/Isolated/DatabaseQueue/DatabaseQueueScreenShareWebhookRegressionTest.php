<?php

namespace Tests\Isolated\DatabaseQueue;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\VideoGrant;
use App\Enums\LiveKitWebhookStatus;
use App\Enums\MeetingMicrophoneMuteResult;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Jobs\ProcessLiveKitWebhook;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\LiveKitWebhookEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
use App\Models\MeetingScreenShareRequest;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\LiveKit\LiveKitParticipantPresence;
use App\Services\LiveKit\LiveKitRoomManager;
use App\Services\LiveKit\LiveKitScreenShareState;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livekit\TrackSource;
use LogicException;
use Tests\TestCase;

/**
 * Isolated regression coverage for production-like database queue behavior.
 * This intentionally does not replace the existing synchronous test.
 */
class DatabaseQueueScreenShareWebhookRegressionTest extends TestCase
{
    private const QUEUE = 'webhook-regression';

    protected function setUp(): void
    {
        // This class requires the dedicated PHPUnit configuration and must not
        // boot Laravel or access any database when invoked through the default
        // suite (or directly without that configuration).
        if (getenv('BBU_DBQ_TEST_CONFIG') !== '1') {
            throw new LogicException('Run this isolated regression with phpunit.database-queue.xml.');
        }

        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'livekit.api_key' => 'test-key',
            'livekit.api_secret' => 'test-secret-that-is-at-least-32-bytes',
            'queue.default' => 'database',
            'queue.connections.database.queue' => self::QUEUE,
            'queue.connections.database.after_commit' => false,
        ]);
    }

    public function test_duplicate_delivery_persists_one_job_and_worker_transitions_once(): void
    {
        [$class, $meeting, $teacher, $student, $participant, $rooms] = $this->approvedFixture([MeetingProviderState::Active]);
        $request = $this->request($class, $meeting, $student);
        $this->actingAs($teacher)->patchJson($this->decisionUrl($class, $meeting, $request), ['decision' => 'approved'])->assertOk();

        $payload = $this->signedPayload($meeting, $participant, 'EV_dbq_duplicate');
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $event = LiveKitWebhookEvent::query()->where('event_id', 'EV_dbq_duplicate')->sole();
        $this->assertSame(LiveKitWebhookStatus::Pending, $event->status);
        $this->assertSame(0, $event->attempts);
        $this->assertSame(1, $this->webhookJobCount());
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $request->fresh()->status);

        $this->runBoundedWorker();

        $event = $event->fresh();
        $startedAt = $request->fresh()->started_at;
        $this->assertSame(LiveKitWebhookStatus::Processed, $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->next_attempt_at);
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $request->fresh()->status);
        $this->assertNotNull($startedAt);
        $this->assertSame(0, $this->webhookJobCount());

        // A duplicate that arrives after durable processing creates no job and
        // cannot cause a second status transition.
        $this->postWebhook($payload)->assertOk();
        $this->assertSame(1, LiveKitWebhookEvent::query()->where('event_id', 'EV_dbq_duplicate')->count());
        $this->assertSame(0, $this->webhookJobCount());
        $this->assertSame(MeetingScreenShareRequestStatus::Sharing, $request->fresh()->status);
        $this->assertTrue($startedAt->equalTo($request->fresh()->started_at));
        $this->assertSame(1, $event->fresh()->attempts);
        $this->assertCount(1, $rooms->screenSharePermissionCalls);
    }

    public function test_worker_failure_is_durable_and_reconciler_retries_once(): void
    {
        [$class, $meeting, $teacher, $student, $participant, $rooms] = $this->approvedFixture([
            MeetingProviderState::Active,
            MeetingProviderState::Unknown,
            MeetingProviderState::Active,
        ]);
        $request = $this->request($class, $meeting, $student);
        $this->actingAs($teacher)->patchJson($this->decisionUrl($class, $meeting, $request), ['decision' => 'approved'])->assertOk();
        $request->update(['expires_at' => now()->subSecond()]);

        $this->postWebhook($this->signedPayload($meeting, $participant, 'EV_dbq_retry'))->assertOk();
        $this->assertSame(1, $this->webhookJobCount());
        $this->runBoundedWorker();

        $event = LiveKitWebhookEvent::query()->where('event_id', 'EV_dbq_retry')->sole();
        $this->assertSame(LiveKitWebhookStatus::Pending, $event->status);
        // The provider-dependent transaction rolls back on failure, including
        // its attempt increment. The durable catch path records retry state
        // without claiming a committed lifecycle transition.
        $this->assertSame(0, $event->attempts);
        $this->assertNotNull($event->next_attempt_at);
        $this->assertSame('Webhook processing is pending retry.', $event->processing_error);
        $this->assertSame(MeetingScreenShareRequestStatus::Approved, $request->fresh()->status);

        $event->update(['next_attempt_at' => now()->subSecond()]);
        // The project PHPUnit listener consumes Artisan output. The durable
        // event transition and fake-provider effects below prove this precise
        // eligible event was synchronously retried by the reconciler.
        $this->artisan('meetings:reconcile-webhooks')->assertSuccessful();

        $event = $event->fresh();
        $this->assertSame(LiveKitWebhookStatus::Processed, $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertSame(MeetingScreenShareRequestStatus::Expired, $request->fresh()->status);
        $this->assertSame(3, count($rooms->screenSharePermissionCalls));
        $this->assertCount(1, $rooms->mutedTrackCalls);

        $this->artisan('meetings:reconcile-webhooks')->assertSuccessful();
        $this->assertSame(1, $event->fresh()->attempts);
        $this->assertSame(3, count($rooms->screenSharePermissionCalls));
    }

    /** @return array{SchoolClass, Meeting, User, User, MeetingParticipant, DatabaseQueueWebhookFakeRoomManager} */
    private function approvedFixture(array $permissionResults): array
    {
        $class = SchoolClass::factory()->create([
            'academic_year_id' => AcademicYear::query()->where('active_slot', 1)->value('id') ?? AcademicYear::factory()->active()->create()->id,
            'status' => SchoolClassStatus::Active,
        ]);
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $teacher->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id]);
        $student = User::factory()->create();
        $student->assignRole('Student');
        $studentProfile = StudentProfile::factory()->create(['user_id' => $student->id]);
        Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
            'current_slot' => 1,
        ]);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id, 'status' => MeetingStatus::Active]);
        $participant = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $student->id]);
        MeetingAttendanceSession::factory()->create(['meeting_participant_id' => $participant->id, 'livekit_participant_sid' => 'PA_database_queue', 'left_at' => null]);

        $rooms = new DatabaseQueueWebhookFakeRoomManager($permissionResults);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        return [$class, $meeting, $teacher, $student, $participant, $rooms];
    }

    private function request(SchoolClass $class, Meeting $meeting, User $student): MeetingScreenShareRequest
    {
        $reference = $this->actingAs($student)->postJson(route('meetings.screen-share-requests.store', [$class, $meeting]))
            ->assertCreated()->json('request.reference');

        return MeetingScreenShareRequest::query()->where('public_uuid', $reference)->sole();
    }

    private function decisionUrl(SchoolClass $class, Meeting $meeting, MeetingScreenShareRequest $request): string
    {
        return route('meetings.screen-share-requests.update', [$class, $meeting, $request]);
    }

    private function signedPayload(Meeting $meeting, MeetingParticipant $participant, string $eventId): array
    {
        return [
            'event' => 'track_published',
            'id' => $eventId,
            'createdAt' => now()->timestamp,
            'room' => ['name' => $meeting->livekit_room_name],
            'participant' => ['identity' => $participant->livekit_identity, 'sid' => 'PA_database_queue'],
            'track' => ['sid' => 'TR_database_queue', 'source' => TrackSource::SCREEN_SHARE, 'type' => 1],
        ];
    }

    private function postWebhook(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $token = (new AccessToken('test-key', 'test-secret-that-is-at-least-32-bytes'))
            ->setGrant(new VideoGrant)->setSha256(base64_encode(hash('sha256', $body, true)))->toJwt();

        return $this->call('POST', route('integrations.livekit.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], $body);
    }

    private function webhookJobCount(): int
    {
        return DB::table('jobs')->where('queue', self::QUEUE)->where('payload', 'like', '%ProcessLiveKitWebhook%')->count();
    }

    private function runBoundedWorker(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => self::QUEUE,
            '--tries' => 1,
            '--max-jobs' => 8,
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]));
    }
}

final class DatabaseQueueWebhookFakeRoomManager implements LiveKitRoomManager
{
    /** @var list<array{string, string, bool}> */
    public array $screenSharePermissionCalls = [];

    /** @var list<array{string, string, string}> */
    public array $mutedTrackCalls = [];

    /** @param list<MeetingProviderState> $permissionResults */
    public function __construct(private array $permissionResults) {}

    public function create(string $roomName, int $maxParticipants): MeetingProviderState { return MeetingProviderState::Active; }
    public function delete(string $roomName): MeetingProviderState { return MeetingProviderState::Ended; }
    public function inspect(string $roomName): MeetingProviderState { return MeetingProviderState::Active; }
    public function removeParticipant(string $roomName, string $identity): MeetingProviderState { return MeetingProviderState::Ended; }
    public function muteParticipantMicrophone(string $roomName, string $identity): MeetingMicrophoneMuteResult { return MeetingMicrophoneMuteResult::Muted; }

    public function setParticipantScreenSharePermission(string $roomName, string $identity, bool $allowed): MeetingProviderState
    {
        $this->screenSharePermissionCalls[] = [$roomName, $identity, $allowed];

        return array_shift($this->permissionResults) ?? MeetingProviderState::Active;
    }

    public function participantPresence(string $roomName, string $identity): LiveKitParticipantPresence
    {
        return new LiveKitParticipantPresence(MeetingProviderState::Active, 'PA_database_queue', CarbonImmutable::parse('2026-09-30T09:00:00+00:00'));
    }

    public function screenShareState(string $roomName, string $identity): LiveKitScreenShareState
    {
        return new LiveKitScreenShareState(MeetingProviderState::Unknown);
    }

    public function mutePublishedTrack(string $roomName, string $identity, string $trackSid): MeetingProviderState
    {
        $this->mutedTrackCalls[] = [$roomName, $identity, $trackSid];

        return MeetingProviderState::Active;
    }
}
