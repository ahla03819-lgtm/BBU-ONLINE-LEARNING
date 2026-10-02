<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingRecordingProviderStatus;
use Carbon\CarbonImmutable;

/**
 * The provider's own record of one egress, as delivered on a verified webhook.
 *
 * This is the only trusted source for whether an egress finished, how long its
 * output was and how big it is. The corresponding fields a browser might send are
 * never consulted.
 */
final readonly class VerifiedEgress
{
    /**
     * @param  list<LiveKitRecordingFile>  $files
     */
    public function __construct(
        public string $egressId,
        public MeetingRecordingProviderStatus $status,
        public array $files = [],
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $endedAt = null,
        public ?string $error = null,
    ) {}
}