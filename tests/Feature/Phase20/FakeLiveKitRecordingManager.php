<?php

namespace Tests\Feature\Phase20;

use App\Enums\MeetingRecordingProviderStatus;
use App\Enums\MeetingRecordingStatus;
use App\Services\LiveKit\LiveKitRecordingFile;
use App\Services\LiveKit\LiveKitRecordingOutcome;
use App\Services\LiveKit\LiveKitRecordingStart;
use App\Services\LiveKit\LiveKitRecordingStatus as ProviderRecordingStatus;
use Carbon\CarbonImmutable;

/**
 * An in-memory LiveKit Recording Manager.
 *
 * It records the exact calls the application made, which is what lets a test assert
 * that the provider was asked to stop exactly once, and lets a test drive the
 * provider's own answers — started, finished with output, failed, or unreachable —
 * without any network.
 */
class FakeLiveKitRecordingManager implements \App\Services\LiveKit\LiveKitRecordingManager
{
    /** @var list<array{room: string, path: string, layout: string}> */
    public array $starts = [];

    /** @var list<string> */
    public array $stops = [];

    public int $inspectCalls = 0;

    public MeetingRecordingProviderStatus $startStatus = MeetingRecordingProviderStatus::Active;

    public MeetingRecordingProviderStatus $stopStatus = MeetingRecordingProviderStatus::Complete;

    /** A provider that cannot be reached at all, distinct from one that refused. */
    public bool $unreachable = false;

    /** @var list<LiveKitRecordingFile> */
    public array $files = [];

    public function __construct(private string $egressId = 'EG_test_egress') {}

    public function startRoomCompositeRecording(string $roomName, string $outputPath, string $layout): LiveKitRecordingStart
    {
        $this->starts[] = ['room' => $roomName, 'path' => $outputPath, 'layout' => $layout];

        if ($this->unreachable) {
            return new LiveKitRecordingStart(MeetingRecordingProviderStatus::Unknown);
        }

        return new LiveKitRecordingStart($this->startStatus, $this->egressId);
    }

    public function stopRecording(string $egressId): LiveKitRecordingOutcome
    {
        $this->stops[] = $egressId;

        if ($this->unreachable) {
            return new LiveKitRecordingOutcome(MeetingRecordingProviderStatus::Unknown);
        }

        return new LiveKitRecordingOutcome($this->stopStatus, $this->files);
    }

    public function inspectRecording(string $egressId): ?ProviderRecordingStatus
    {
        $this->inspectCalls++;

        if ($this->unreachable) {
            return null;
        }

        return new ProviderRecordingStatus(
            MeetingRecordingProviderStatus::Complete,
            $egressId,
            $this->files,
            CarbonImmutable::parse('2026-10-03T09:00:00+00:00'),
            CarbonImmutable::parse('2026-10-03T09:12:00+00:00'),
        );
    }

    public function file(int $durationSeconds, int $sizeBytes): self
    {
        $this->files = [new LiveKitRecordingFile('recording.mp4', $durationSeconds, $sizeBytes, 'file:///egress/recording.mp4')];

        return $this;
    }

    public function ready(): self
    {
        return $this->file(720, 4_200_000);
    }
}
