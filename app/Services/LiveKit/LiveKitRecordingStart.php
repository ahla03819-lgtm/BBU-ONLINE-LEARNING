<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingRecordingProviderStatus;

/**
 * The provider's answer to a start request.
 *
 * A missing egress id is the explicit "the capture is not running" signal, kept
 * distinct from Unknown so a caller can fail an attempt immediately instead of
 * publishing a recording that never existed.
 */
final readonly class LiveKitRecordingStart
{
    public function __construct(
        public MeetingRecordingProviderStatus $status,
        public ?string $egressId = null,
        public ?string $error = null,
    ) {}

    public function started(): bool
    {
        return $this->egressId !== null
            && in_array($this->status, [MeetingRecordingProviderStatus::Starting, MeetingRecordingProviderStatus::Active], true);
    }
}