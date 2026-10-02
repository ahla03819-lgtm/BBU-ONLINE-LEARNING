<?php

namespace App\Services\Recordings;

use App\Models\MeetingRecording;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Moves a finished provider output into storage this application owns.
 *
 * The provider writes to a relative path we chose. Playback is then served from
 * app-owned storage through an authorised controller, so a recording file is
 * never reachable by a provider URL or a storage path a client could guess.
 *
 * When the egress service runs on its own host that path does not exist on the
 * application disk. This reports that honestly instead of inventing a ready
 * state: the caller keeps the recording in Processing and retries within a
 * bounded window before failing it.
 */
class MeetingRecordingFileStore
{
    /**
     * The relative path handed to the provider for this recording.
     *
     * It is derived from the recording's own public reference, so the provider
     * can only ever write where this application expects, and one recording can
     * never overwrite another's output.
     */
    public function outputPathFor(MeetingRecording $recording): string
    {
        return sprintf(
            '%s/%s/%s.%s',
            trim((string) config('meeting-recordings.path_prefix'), '/'),
            $recording->public_uuid,
            $recording->public_uuid,
            (string) config('meeting-recordings.file_type'),
        );
    }

    /**
     * Whether the provider's finished output is readable on the configured disk.
     */
    public function outputExists(MeetingRecording $recording): bool
    {
        $path = $recording->provider_output_path;
        if (! is_string($path) || $path === '') {
            return false;
        }

        return $this->disk()->exists($path);
    }

    /**
     * Record the stored object for a recording, or null when it is not there yet.
     *
     * Duration is only accepted from the provider's own report and is bounded by
     * the wall-clock span between the authoritative start and stop, so a
     * nonsensical provider duration cannot reach the channel card.
     */
    public function collect(MeetingRecording $recording, int $providerDurationSeconds = 0): ?array
    {
        $disk = $this->disk();
        $path = $recording->provider_output_path;
        if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
            return null;
        }

        $recordedAt = $recording->started_at && $recording->stopped_at
            ? (int) $recording->started_at->diffInSeconds($recording->stopped_at)
            : 0;
        $duration = $providerDurationSeconds > 0
            ? min($providerDurationSeconds, $recordedAt > 0 ? $recordedAt : $providerDurationSeconds)
            : $recordedAt;

        return [
            'storage_disk' => config('meeting-recordings.disk'),
            'storage_path' => $path,
            'mime_type' => (string) config('meeting-recordings.mime_type'),
            'original_name' => $recording->public_uuid.'.'.config('meeting-recordings.file_type'),
            'size_bytes' => max(0, (int) $disk->size($path)),
            'duration_seconds' => max(0, $duration),
        ];
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('meeting-recordings.disk'));
    }
}