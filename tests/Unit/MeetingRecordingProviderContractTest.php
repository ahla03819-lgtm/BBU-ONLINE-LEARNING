<?php

namespace Tests\Unit;

use Agence104\LiveKit\EgressServiceClient;
use App\Enums\MeetingRecordingProviderStatus;
use App\Services\LiveKit\EgressInfoMapper;
use App\Services\LiveKit\LiveKitRecordingOutputFactory;
use App\Services\LiveKit\SdkLiveKitRecordingManager;
use Livekit\EgressInfo;
use Livekit\EgressStatus;
use Livekit\FileInfo;
use Livekit\ListEgressResponse;
use Livekit\TwirpError;
use Mockery;
use Tests\TestCase;
use Twirp\ErrorCode;

class MeetingRecordingProviderContractTest extends TestCase
{
    public function test_file_duration_nanoseconds_are_truncated_to_whole_seconds(): void
    {
        $file = EgressInfoMapper::file(new FileInfo(['duration' => '64295971933']));

        $this->assertSame(64, $file->durationSeconds);
    }

    public function test_zero_missing_negative_and_invalid_durations_are_safe(): void
    {
        $this->assertSame(0, EgressInfoMapper::durationSeconds(0));
        $this->assertSame(0, EgressInfoMapper::file(new FileInfo)->durationSeconds);
        $this->assertSame(0, EgressInfoMapper::durationSeconds(-1));
        $this->assertSame(0, EgressInfoMapper::durationSeconds('not-a-duration'));
        $this->assertSame(0, EgressInfoMapper::durationSeconds('9223372036854775808'));
    }

    public function test_large_valid_durations_are_not_capped_or_converted_through_float(): void
    {
        $this->assertSame(172800, EgressInfoMapper::durationSeconds('172800999999999'));
        $this->assertSame(4294967295, EgressInfoMapper::durationSeconds('4294967295999999999'));
    }

    public function test_duration_larger_than_the_existing_storage_contract_is_rejected_instead_of_overflowing(): void
    {
        $this->assertSame(0, EgressInfoMapper::durationSeconds('4294967296000000000'));
        $this->assertSame(0, EgressInfoMapper::durationSeconds('9223372036854775807'));
    }

    public function test_egress_timestamps_convert_nanoseconds_without_float_precision_loss(): void
    {
        $this->assertSame('2025-10-05T09:42:01+00:00', EgressInfoMapper::timestamp(1_759_657_321_987_654_321)?->toIso8601String());
        $this->assertSame('2025-10-05T08:35:21+00:00', EgressInfoMapper::timestamp('1759653321123456789')?->toIso8601String());
    }

    public function test_egress_timestamp_rejects_absent_invalid_and_out_of_contract_values(): void
    {
        $this->assertNull(EgressInfoMapper::timestamp(0));
        $this->assertNull(EgressInfoMapper::timestamp(-1));
        $this->assertNull(EgressInfoMapper::timestamp('not-a-timestamp'));
        $this->assertNull(EgressInfoMapper::timestamp('9223372036854775808'));
        $this->assertSame('2262-04-11T23:47:16+00:00', EgressInfoMapper::timestamp('9223372036854775807')?->toIso8601String());
    }

    public function test_stop_and_inspection_use_the_same_duration_mapping(): void
    {
        $info = $this->egress(EgressStatus::EGRESS_COMPLETE, '64295971933');
        $client = Mockery::mock(EgressServiceClient::class);
        $client->shouldReceive('stopEgress')->once()->with('EG_test')->andReturn($info);
        $client->shouldReceive('listEgress')->once()->with('', 'EG_test')->andReturn(new ListEgressResponse(['items' => [$info]]));
        $manager = $this->manager($client);

        $this->assertSame(64, $manager->stopRecording('EG_test')->files[0]->durationSeconds);
        $this->assertSame(64, $manager->inspectRecording('EG_test')->files[0]->durationSeconds);
    }

    public function test_inspection_maps_complete_active_and_failed_egress_timestamps(): void
    {
        $complete = $this->egress(EgressStatus::EGRESS_COMPLETE, startedAt: '1759653321987654321', endedAt: 1_759_657_381_999_999_999);
        $active = $this->egress(EgressStatus::EGRESS_ACTIVE, startedAt: 1_759_657_321_000_000_000);
        $failed = $this->egress(EgressStatus::EGRESS_FAILED, error: 'provider failed');
        $client = Mockery::mock(EgressServiceClient::class);
        $client->shouldReceive('listEgress')->once()->with('', 'EG_complete')->andReturn(new ListEgressResponse(['items' => [$complete]]));
        $client->shouldReceive('listEgress')->once()->with('', 'EG_active')->andReturn(new ListEgressResponse(['items' => [$active]]));
        $client->shouldReceive('listEgress')->once()->with('', 'EG_failed')->andReturn(new ListEgressResponse(['items' => [$failed]]));
        $manager = $this->manager($client);

        $completeStatus = $manager->inspectRecording('EG_complete');
        $this->assertSame(MeetingRecordingProviderStatus::Complete, $completeStatus?->status);
        $this->assertSame('2025-10-05T08:35:21+00:00', $completeStatus?->startedAt?->toIso8601String());
        $this->assertSame('2025-10-05T09:43:01+00:00', $completeStatus?->endedAt?->toIso8601String());
        $this->assertSame(MeetingRecordingProviderStatus::Active, $manager->inspectRecording('EG_active')?->status);
        $this->assertSame(MeetingRecordingProviderStatus::Failed, $manager->inspectRecording('EG_failed')?->status);
    }

    public function test_failed_precondition_reconciles_an_already_completed_egress(): void
    {
        $info = $this->egress(EgressStatus::EGRESS_COMPLETE, '64295971933');
        $client = Mockery::mock(EgressServiceClient::class);
        $client->shouldReceive('stopEgress')->once()->andThrow(TwirpError::newError(ErrorCode::FailedPrecondition, 'not active'));
        $client->shouldReceive('listEgress')->once()->with('', 'EG_test')->andReturn(new ListEgressResponse(['items' => [$info]]));

        $outcome = $this->manager($client)->stopRecording('EG_test');

        $this->assertSame(MeetingRecordingProviderStatus::Complete, $outcome->status);
        $this->assertSame(64, $outcome->files[0]->durationSeconds);
    }

    public function test_failed_precondition_preserves_live_or_unknown_provider_state(): void
    {
        $active = $this->egress(EgressStatus::EGRESS_ACTIVE);
        $client = Mockery::mock(EgressServiceClient::class);
        $client->shouldReceive('stopEgress')->twice()->andThrow(TwirpError::newError(ErrorCode::FailedPrecondition, 'not stoppable'));
        $client->shouldReceive('listEgress')->once()->with('', 'EG_active')->andReturn(new ListEgressResponse(['items' => [$active]]));
        $client->shouldReceive('listEgress')->once()->with('', 'EG_unknown')->andReturn(new ListEgressResponse);
        $manager = $this->manager($client);

        $this->assertSame(MeetingRecordingProviderStatus::Active, $manager->stopRecording('EG_active')->status);
        $this->assertSame(MeetingRecordingProviderStatus::Unknown, $manager->stopRecording('EG_unknown')->status);
    }

    public function test_failed_precondition_preserves_a_confirmed_provider_failure(): void
    {
        $failed = $this->egress(EgressStatus::EGRESS_FAILED, error: 'provider failed');
        $client = Mockery::mock(EgressServiceClient::class);
        $client->shouldReceive('stopEgress')->once()->andThrow(TwirpError::newError(ErrorCode::FailedPrecondition, 'not active'));
        $client->shouldReceive('listEgress')->once()->with('', 'EG_failed')->andReturn(new ListEgressResponse(['items' => [$failed]]));

        $outcome = $this->manager($client)->stopRecording('EG_failed');

        $this->assertSame(MeetingRecordingProviderStatus::Failed, $outcome->status);
        $this->assertSame('provider failed', $outcome->error);
        $this->assertSame([], $outcome->files);
    }

    private function manager(EgressServiceClient $client): SdkLiveKitRecordingManager
    {
        config([
            'meeting-recordings.egress_enabled' => true,
            'meeting-recordings.egress_api_url' => 'https://egress.invalid',
            'livekit.api_key' => 'test-key',
            'livekit.api_secret' => 'test-secret',
        ]);
        $output = Mockery::mock(LiveKitRecordingOutputFactory::class);
        $output->shouldReceive('isUsable')->andReturnTrue();

        return new SdkLiveKitRecordingManager($client, $output);
    }

    private function egress(
        int $status,
        int|string $duration = 0,
        string $error = '',
        int|string $startedAt = 0,
        int|string $endedAt = 0,
    ): EgressInfo {
        $files = $duration === 0 ? [] : [new FileInfo([
            'filename' => 'recording.mp4',
            'duration' => $duration,
            'size' => 1024,
            'location' => 'private://recording.mp4',
        ])];

        return new EgressInfo([
            'egress_id' => 'EG_test',
            'status' => $status,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'file_results' => $files,
            'error' => $error,
        ]);
    }
}
