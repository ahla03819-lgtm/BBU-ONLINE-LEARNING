<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingRecordingProviderStatus;

/**
 * What the provider reported when a stop was requested.
 *
 * Duration and size come from the provider's own FileInfo record; the caller
 * never takes either from a browser.
 */
final readonly class LiveKitRecordingOutcome
{
    /**
     * @param  list<LiveKitRecordingFile>  $files
     */
    public function __construct(
        public MeetingRecordingProviderStatus $status,
        public array $files = [],
        public ?string $error = null,
    ) {}
}