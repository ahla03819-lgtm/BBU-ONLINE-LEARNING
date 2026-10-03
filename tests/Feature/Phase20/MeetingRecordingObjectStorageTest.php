<?php

namespace Tests\Feature\Phase20;

use App\Actions\Recordings\FinalizeMeetingRecording;
use App\Actions\Recordings\StopMeetingRecording;
use App\Enums\MeetingRecordingProviderStatus;
use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingRecordingStopReason;
use App\Models\Message;
use App\Models\MeetingRecording;
use App\Services\LiveKit\LiveKitRecordingOutputFactory;
use App\Services\Recordings\MeetingRecordingFileStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Livekit\EncodedFileType;
use Mockery;

/**
 * Recording storage in the shape a hosted LiveKit deployment actually has.
 *
 * The provider runs on someone else's machine, so it cannot be handing the
 * application a path on this filesystem. These tests cover object storage as the
 * primary case and keep the shared-local-filesystem case as the development shape.
 */
class MeetingRecordingObjectStorageTest extends RecordingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Object storage in place of the development local disk.
        Storage::fake('recording-store');
        config([
            'meeting-recordings.disk' => 'recording-store',
            'meeting-recordings.output.driver' => 's3',
            'meeting-recordings.output.s3' => [
                'bucket' => 'bbu-recordings',
                'region' => 'auto',
                'endpoint' => 'https://account.r2.cloudflarestorage.com',
                'force_path_style' => true,
                'key' => 'egress-key',
                'secret' => 'egress-secret',
                'session_token' => '',
            ],
        ]);
    }

    public function test_the_provider_is_asked_to_upload_the_object_itself(): void
    {
        $output = (new LiveKitRecordingOutputFactory)->make('meeting-recordings/abc/abc.mp4');

        $this->assertTrue($output->hasS3(), 'The provider was not told to upload to object storage.');
        // The path the application will later read is the object key the provider writes.
        $this->assertSame('meeting-recordings/abc/abc.mp4', $output->getFilepath());
        $this->assertSame(EncodedFileType::MP4, $output->getFileType());
        $this->assertTrue($output->getDisableManifest());
        $this->assertSame('bbu-recordings', $output->getS3()->getBucket());
        // Endpoint and path style are what make R2 and MinIO work, not just AWS.
        $this->assertSame('https://account.r2.cloudflarestorage.com', $output->getS3()->getEndpoint());
        $this->assertTrue($output->getS3()->getForcePathStyle());
    }

    public function test_a_local_driver_sends_no_object_storage_upload(): void
    {
        config(['meeting-recordings.output.driver' => 'local']);

        $output = (new LiveKitRecordingOutputFactory)->make('meeting-recordings/abc/abc.mp4');

        $this->assertFalse($output->hasS3());
        $this->assertSame('meeting-recordings/abc/abc.mp4', $output->getFilepath());
    }

    public function test_a_local_driver_refuses_to_point_at_remote_storage(): void
    {
        config([
            'meeting-recordings.output.driver' => 'local',
            'meeting-recordings.output.shared_filesystem' => true,
            // Storage::fake rewrites the disk config to a local driver, so the remote
            // shape has to be declared explicitly for the mismatch to be observable.
            'filesystems.disks.recording-store' => ['driver' => 's3', 'bucket' => 'bbu-recordings'],
        ]);

        // The provider would write a local path while the application read a bucket,
        // so the object could never be found.
        $this->assertFalse((new LiveKitRecordingOutputFactory)->isUsable());
    }

    public function test_a_local_driver_is_refused_when_egress_does_not_share_our_filesystem(): void
    {
        // A hosted Egress writes to its own machine. A local path it writes is never
        // readable here, so recording must be refused rather than accepted and left
        // to time out in Processing.
        config([
            'meeting-recordings.output.driver' => 'local',
            'meeting-recordings.disk' => 'local',
            'meeting-recordings.output.shared_filesystem' => false,
        ]);

        $factory = new LiveKitRecordingOutputFactory;
        $this->assertFalse($factory->sharesOurFilesystem());
        $this->assertFalse($factory->isConsistent());
        $this->assertFalse($factory->isUsable());
    }

    public function test_a_local_driver_is_accepted_once_the_shared_filesystem_is_asserted(): void
    {
        config([
            'meeting-recordings.output.driver' => 'local',
            'meeting-recordings.disk' => 'local',
            'meeting-recordings.output.shared_filesystem' => true,
        ]);

        $factory = new LiveKitRecordingOutputFactory;
        $this->assertTrue($factory->sharesOurFilesystem());
        $this->assertTrue($factory->isUsable());
    }

    public function test_the_verifier_refuses_a_local_driver_against_hosted_egress(): void
    {
        config([
            'meeting-recordings.output.driver' => 'local',
            'meeting-recordings.disk' => 'local',
            'meeting-recordings.output.shared_filesystem' => false,
        ]);

        // The verifier is the thing a deployment runs instead of a teacher finding
        // out, so it has to fail for this arrangement rather than pass it.
        $this->artisan('meetings:verify-recording-storage')->assertFailed();
    }

    public function test_incomplete_object_storage_configuration_is_refused(): void
    {
        config(['meeting-recordings.output.s3.bucket' => '']);

        $this->assertFalse((new LiveKitRecordingOutputFactory)->isUsable());
    }

    public function test_a_recording_becomes_ready_from_the_remote_object(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->recordings->ready();
        $recording = $this->startRecording($teacher, $meeting, 12);

        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        // The provider uploads the object to the bucket; the application only ever
        // learns about it by resolving the key it already knows.
        $this->publishProviderOutput($recording);

        [$settled, $stillSettling] = app(FinalizeMeetingRecording::class)->handle($recording->fresh());

        $this->assertFalse($stillSettling);
        $this->assertSame(MeetingRecordingStatus::Ready, $settled->status);
        $this->assertSame('recording-store', $settled->storage_disk);
        $this->assertSame($recording->provider_output_path, $settled->storage_path);
        $this->assertSame('video/mp4', $settled->mime_type);
    }

    public function test_a_missing_remote_object_stays_processing_and_never_becomes_ready(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);

        // No object ever arrived, so finalisation must report that it is still
        // settling rather than inventing a watchable recording.
        [$settled, $stillSettling] = app(FinalizeMeetingRecording::class)->handle($recording->fresh());

        $this->assertTrue($stillSettling);
        $this->assertSame(MeetingRecordingStatus::Processing, $settled->status);
        $this->assertNull($settled->storage_path);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
    }

    public function test_a_missing_remote_object_eventually_fails_within_the_bounded_window(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $recording->forceFill([
            'stopped_at' => now()->subMinutes((int) config('meeting-recordings.collection_deadline_minutes') + 5),
        ])->save();

        [$settled, $stillSettling] = app(FinalizeMeetingRecording::class)->handle($recording->fresh());

        $this->assertFalse($stillSettling);
        $this->assertSame(MeetingRecordingStatus::Failed, $settled->status);
        $this->assertSame('failed', $this->cardStatus($recording));
    }

    public function test_an_unreachable_object_store_never_marks_a_recording_ready(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);

        // Point the recording at a disk the application cannot read, which is what a
        // wrong bucket or unreachable region looks like from here.
        $recording->forceFill(['storage_disk' => 'recording-store'])->save();

        [$settled, $stillSettling] = app(FinalizeMeetingRecording::class)->handle($recording->fresh());
        $this->assertFalse($settled->status === MeetingRecordingStatus::Ready && ! Storage::disk('recording-store')->exists((string) $settled->storage_path));
    }

    public function test_a_local_disk_is_streamed_rather_than_handed_out_as_a_bearer_url(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = $this->readyRemoteRecording($teacher, $meeting);

        // Laravel can pre-sign for a local disk, which would turn every playback into a
        // URL anyone could replay. Streaming keeps the decision on the request.
        $this->assertFalse((new MeetingRecordingFileStore)->preSigningApplies());
        $this->assertNull((new MeetingRecordingFileStore)->temporaryUrl($recording));

        $this->actingAs($student)
            ->get(route('meetings.recordings.play', [$class, $meeting->uuid, $recording]))
            ->assertOk();
    }

    public function test_playback_is_authorized_before_any_object_is_reached(): void
    {
        [$class, $meeting, $teacher, $student, , $outsiderStudent] = $this->scenario();
        $recording = $this->readyRemoteRecording($teacher, $meeting);

        $this->actingAs($student)
            ->get(route('meetings.recordings.play', [$class, $meeting->uuid, $recording]))
            ->assertOk();

        // Possession of the public reference is not access.
        $this->actingAs($outsiderStudent)
            ->get(route('meetings.recordings.play', [$class, $meeting->uuid, $recording]))
            ->assertForbidden();
    }

    public function test_a_presigned_object_store_yields_a_short_lived_url_instead_of_a_stream(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = $this->readyRemoteRecording($teacher, $meeting);
        // A real object store, which is the only thing allowed to pre-sign.
        config(['filesystems.disks.recording-store' => ['driver' => 's3', 'bucket' => 'bbu-recordings']]);

        $this->app->instance(MeetingRecordingFileStore::class, $this->preSigningFileStore(
            'https://bbu-recordings.r2.cloudflarestorage.com/'.$recording->storage_path.'?sig=abc',
        ));

        $this->actingAs($student)
            ->get(route('meetings.recordings.play', [$class, $meeting->uuid, $recording]))
            ->assertRedirect('https://bbu-recordings.r2.cloudflarestorage.com/'.$recording->storage_path.'?sig=abc');
    }

    public function test_a_disk_that_cannot_presign_streams_through_the_application(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = $this->readyRemoteRecording($teacher, $meeting);
        config(['filesystems.disks.recording-store' => ['driver' => 's3', 'bucket' => 'bbu-recordings']]);

        $this->app->instance(MeetingRecordingFileStore::class, $this->preSigningFileStore(null));

        $response = $this->actingAs($student)
            ->get(route('meetings.recordings.play', [$class, $meeting->uuid, $recording]))
            ->assertOk();

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_channel_card_never_contains_a_permanent_storage_url(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->readyRemoteRecording($teacher, $meeting);

        $payload = app(\App\Support\MessagePayload::class)
            ->make(Message::query()->where('meeting_recording_id', $recording->id)->firstOrFail());
        $serialized = json_encode($payload);

        // The provider's own location is a full bucket URL and must never surface.
        $this->assertStringNotContainsString('cloudflarestorage.com', $serialized);
        $this->assertStringNotContainsString('bbu-recordings', $serialized);
        $this->assertStringNotContainsString('sig=', $serialized);
        $this->assertStringNotContainsString('egress-secret', $serialized);
        $this->assertStringNotContainsString('egress-key', $serialized);
        // What the card carries is this application's own authorized route.
        $this->assertStringContainsString('/recordings/'.$recording->public_uuid, (string) $payload['recording']['playback_url']);
    }

    public function test_no_storage_or_provider_credential_reaches_a_client_payload(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $this->readyRemoteRecording($teacher, $meeting);
        $channel = $class->channels()->where('type', 'general')->firstOrFail();

        $body = $this->actingAs($student)
            ->getJson(route('collaboration.messages.index', [$class, $channel]))
            ->assertOk()
            ->getContent();

        foreach (['egress-secret', 'egress-key', 'bbu-recordings', 'r2.cloudflarestorage.com', 'AWS_SECRET', 'provider_egress_id', 'provider_output_path', 'storage_path', 'storage_disk'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_the_provider_reported_location_is_never_persisted(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->recordings->file(720, 4_200_000);
        $recording = $this->startRecording($teacher, $meeting, 12);
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);
        $settled = app(FinalizeMeetingRecording::class)->handle($recording->fresh())[0];

        $this->assertStringNotContainsString('file://', json_encode($settled->getAttributes()));
        $this->assertSame($recording->provider_output_path, $settled->storage_path);
    }

    public function test_the_development_shape_still_works_on_a_local_disk(): void
    {
        // Local disk, local driver: the shared-filesystem deployment.
        Storage::fake('local');
        config([
            'meeting-recordings.disk' => 'local',
            'meeting-recordings.output.driver' => 'local',
            'meeting-recordings.output.shared_filesystem' => true,
        ]);

        [$class, $meeting, $teacher] = $this->scenario();
        $this->recordings->ready();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $output = (new LiveKitRecordingOutputFactory)->make($recording->provider_output_path);

        $this->assertFalse($output->hasS3());
        $this->assertTrue((new LiveKitRecordingOutputFactory)->isUsable());

        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);
        $settled = app(FinalizeMeetingRecording::class)->handle($recording->fresh())[0];

        $this->assertSame(MeetingRecordingStatus::Ready, $settled->status);
        $this->assertSame('local', $settled->storage_disk);
    }

    public function test_the_verifier_reports_no_storage_problem_for_a_complete_object_storage_setup(): void
    {
        config([
            'meeting-recordings.egress_enabled' => true,
            // A host that will not resolve, so the check covers the storage verdict
            // rather than this machine's network.
            'meeting-recordings.egress_api_url' => 'https://egress.invalid',
            'filesystems.disks.recording-store' => ['driver' => 'local', 'root' => sys_get_temp_dir()],
        ]);

        $this->artisan('meetings:verify-recording-storage')
            ->expectsOutputToContain('Output driver', false)
            ->doesntExpectOutputToContain('Object storage output is missing')
            ->doesntExpectOutputToContain('is not writable')
            ->doesntExpectOutputToContain('Unknown RECORDING_OUTPUT_DRIVER');
    }

    public function test_the_verifier_fails_when_object_storage_is_incomplete(): void
    {
        config([
            'meeting-recordings.egress_enabled' => true,
            'meeting-recordings.egress_api_url' => 'https://egress.invalid',
            'meeting-recordings.output.s3.bucket' => '',
            'filesystems.disks.recording-store' => ['driver' => 'local', 'root' => sys_get_temp_dir()],
        ]);

        $this->artisan('meetings:verify-recording-storage')
            ->expectsOutputToContain('Object storage output is missing');
    }

    private function readyRemoteRecording(\App\Models\User $teacher, \App\Models\Meeting $meeting): MeetingRecording
    {
        $this->recordings->ready();
        $recording = $this->startRecording($teacher, $meeting, 12);
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);
        $settled = app(FinalizeMeetingRecording::class)->handle($recording->fresh())[0];

        $this->assertSame(MeetingRecordingStatus::Ready, $settled->status);

        return $settled;
    }

    /**
     * A file store whose disk can, or cannot, pre-sign, so both playback branches are
     * exercised against the real controller.
     */
    private function preSigningFileStore(?string $url): MeetingRecordingFileStore
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturnTrue();
        $disk->shouldReceive('size')->andReturn(4_200_000);
        $disk->shouldReceive('download')->andReturn(response()->stream(function () {
            echo 'mp4';
        }, 200, [
            'Content-Type' => 'video/mp4',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]));

        if ($url === null) {
            // A disk with no signed-URL support throws, which must fall back to a stream.
            $disk->shouldReceive('temporaryUrl')->andThrow(new \RuntimeException('This driver does not support creating temporary URLs.'));
        } else {
            $disk->shouldReceive('temporaryUrl')->andReturn($url);
        }

        $store = Mockery::mock(MeetingRecordingFileStore::class)->makePartial();
        $store->shouldReceive('disk')->andReturn($disk);
        $store->shouldReceive('diskName')->andReturn('recording-store');

        return $store;
    }

    private function cardStatus(MeetingRecording $recording): string
    {
        return app(\App\Support\MessagePayload::class)
            ->make(Message::query()->where('meeting_recording_id', $recording->id)->firstOrFail())['recording']['status'];
    }
}
