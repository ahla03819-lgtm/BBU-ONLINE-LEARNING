<?php

namespace App\Services\LiveKit;

/**
 * One output file the provider produced for a finished egress.
 *
 * $relativePath is the provider's own report of what it wrote. It is only ever
 * compared against the path this application asked for; it is never used to read
 * an arbitrary object.
 */
final readonly class LiveKitRecordingFile
{
    public function __construct(
        public string $filename,
        public int $durationSeconds,
        public int $sizeBytes,
        public string $location,
    ) {}
}