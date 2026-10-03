<?php

namespace App\Services\Recordings;

use App\Enums\MeetingRecordingStatus;
use App\Models\MeetingRecording;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Resolves where a finished recording lives and how it may be handed out.
 *
 * Nothing here assumes the provider shares this application's filesystem. The
 * recording names an ordinary Laravel disk and a path inside it, and every
 * question is answered through that disk:
 *
 *  - did the provider's output actually arrive, which is a local stat() or a
 *    remote HEAD request depending on the disk;
 *  - what are its size and duration;
 *  - how may an authorised viewer be given the bytes.
 *
 * That is what lets the same code serve a development recording off a local disk
 * and a production recording out of S3, Cloudflare R2 or MinIO. In object-storage
 * mode the provider uploads the object itself, so availability is resolved against
 * the bucket rather than waiting for a local path that will never exist.
 *
 * The provider's own reported location is deliberately never stored or returned.
 * On object storage that value is a full provider URL, which is exactly the kind
 * of permanent reference this feature must not hand out.
 */
class MeetingRecordingFileStore
{
    /**
     * The path handed to the provider, and therefore the object key or relative
     * file path the recording will live at.
     *
     * It is derived from the recording's own public reference, so the provider can
     * only ever write where this application expects, and one recording can never
     * overwrite another's output.
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
     *
     * A remote store that cannot be reached reports false, which keeps the recording
     * in Processing and lets the bounded collection window decide its fate. That is
     * the safe direction: the application never claims a file it has not seen.
     */
    public function outputExists(MeetingRecording $recording): bool
    {
        if (! $this->hasPath($recording)) {
            return false;
        }

        return $this->disk()->exists($recording->provider_output_path);
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
        if (! $this->hasPath($recording) || ! $this->outputExists($recording)) {
            return null;
        }

        $recordedAt = $recording->started_at && $recording->stopped_at
            ? (int) $recording->started_at->diffInSeconds($recording->stopped_at)
            : 0;
        $duration = $providerDurationSeconds > 0
            ? min($providerDurationSeconds, $recordedAt > 0 ? $recordedAt : $providerDurationSeconds)
            : $recordedAt;

        return [
            'storage_disk' => $this->diskName(),
            'storage_path' => $recording->provider_output_path,
            'mime_type' => (string) config('meeting-recordings.mime_type'),
            'original_name' => $recording->public_uuid.'.'.config('meeting-recordings.file_type'),
            'size_bytes' => max(0, (int) $this->disk()->size($recording->provider_output_path)),
            'duration_seconds' => max(0, $duration),
        ];
    }

    /**
     * A short-lived read URL for an authorised viewer, or null when the bytes have to
     * be streamed instead.
     *
     * Object stores that can pre-sign hand the bytes straight from the bucket. The
     * URL is generated per request, after authorization has already passed, and is
     * never stored on the recording or written into a channel message, so it cannot
     * outlive the permission that produced it.
     *
     * A local disk is deliberately excluded even though Laravel can pre-sign for it.
     * Streaming the file through the authorized controller keeps every playback a
     * decision made on the viewer's own request, rather than handing out a bearer URL
     * for a disk that can serve the bytes perfectly well without one.
     */
    public function temporaryUrl(MeetingRecording $recording): ?string
    {
        if (! $this->preSigningApplies() || ! $this->isWatchable($recording)) {
            return null;
        }

        try {
            $url = $this->disk()->temporaryUrl(
                $recording->storage_path,
                now()->addSeconds((int) config('meeting-recordings.playback_url_ttl', 300)),
                ['ResponseContentDisposition' => 'inline'],
            );
        } catch (Throwable) {
            // A bucket without a public hostname configured, or an endpoint that
            // cannot sign, simply streams through the application instead.
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Whether this recording's disk is remote storage that can pre-sign reads.
     */
    public function preSigningApplies(): bool
    {
        return (string) (config('filesystems.disks.'.$this->diskName().'.driver') ?? 'local') !== 'local';
    }

    public function diskName(): string
    {
        return (string) config('meeting-recordings.disk');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function hasPath(MeetingRecording $recording): bool
    {
        return is_string($recording->provider_output_path) && $recording->provider_output_path !== '';
    }

    private function isWatchable(MeetingRecording $recording): bool
    {
        return $recording->status === MeetingRecordingStatus::Ready
            && is_string($recording->storage_path)
            && $recording->storage_path !== ''
            && $recording->storage_disk === $this->diskName();
    }
}
