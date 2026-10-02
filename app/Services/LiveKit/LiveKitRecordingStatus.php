<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingRecordingProviderStatus;
use Carbon\CarbonImmutable;

/**
 * An authoritative re-read of one egress.
 *
 * A provider that cannot be asked yields a null status rather than a synthetic
 * one, because silence is not evidence that the egress ended.
 */
final readonly class LiveKitRecordingStatus
{
    /**
     * @param  list<LiveKitRecordingFile>  $files
     */
    public function __construct(
        public MeetingRecordingProviderStatus $status,
        public string $egressId,
        public array $files = [],
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $endedAt = null,
        public ?string $error = null,
    ) {}
}