<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingRecordingProviderStatus;
use Livekit\EncodedFileOutput;
use Livekit\EncodedFileType;
use Livekit\S3Upload;

/**
 * Builds the provider's request for a finished recording object.
 *
 * This is separated from the SDK client so the decision that matters for a hosted
 * deployment can be stated once, plainly, and tested without a socket.
 *
 * In local mode the provider writes a relative path onto a filesystem the
 * application shares with it. That is correct for development and for a
 * self-hosted Egress on the same host, and wrong everywhere else: on LiveKit Cloud
 * the Egress worker runs on someone else's machine, so a path it writes is
 * unreachable here and the recording could never be collected.
 *
 * In object-storage mode the provider uploads the finished object itself, which is
 * the only mode that works when it does not share our filesystem. The application
 * then resolves availability against the same bucket through an ordinary Laravel
 * disk. The chosen path doubles as the object key, so the two agree on exactly one
 * location.
 *
 * A manifest is disabled in both modes: it would produce an HLS playlist this
 * application never serves and would leave the provider holding its own index of
 * the finished file.
 */
class LiveKitRecordingOutputFactory
{
    public const DRIVER_LOCAL = 'local';

    public const DRIVER_S3 = 's3';

    public function make(string $outputPath): EncodedFileOutput
    {
        $output = new EncodedFileOutput([
            'file_type' => EncodedFileType::MP4,
            'filepath' => $outputPath,
            'disable_manifest' => true,
        ]);

        if (! $this->usesObjectStorage()) {
            return $output;
        }

        $s3 = (array) config('meeting-recordings.output.s3');
        $output->setS3(new S3Upload([
            'bucket' => (string) ($s3['bucket'] ?? ''),
            'region' => (string) ($s3['region'] ?? ''),
            // Empty for AWS S3. Cloudflare R2 and MinIO both need their own endpoint,
            // which is why it is passed through rather than derived from the region.
            'endpoint' => (string) ($s3['endpoint'] ?? ''),
            'force_path_style' => (bool) ($s3['force_path_style'] ?? false),
            'access_key' => (string) ($s3['key'] ?? ''),
            'secret' => (string) ($s3['secret'] ?? ''),
            'session_token' => (string) ($s3['session_token'] ?? ''),
        ]));

        return $output;
    }

    /**
     * Whether the provider will upload the object rather than write a local path.
     */
    public function usesObjectStorage(): bool
    {
        return (string) config('meeting-recordings.output.driver') === self::DRIVER_S3;
    }

    /**
     * Whether a recording could ever be collected with the current configuration.
     *
     * The two halves have to agree. A provider writing a local path while the
     * application reads a remote disk means the object is never found, and every
     * recording quietly times out instead of failing at the moment it starts.
     */
    public function isConsistent(): bool
    {
        $driver = (string) config('meeting-recordings.output.driver');
        $disk = (string) config('meeting-recordings.disk');
        $diskDriver = (string) (config("filesystems.disks.{$disk}.driver") ?? self::DRIVER_LOCAL);

        if ($driver === self::DRIVER_LOCAL) {
            return $diskDriver === self::DRIVER_LOCAL;
        }

        return $driver === self::DRIVER_S3 && $this->hasRequiredS3Settings();
    }

    public function isUsable(): bool
    {
        $driver = (string) config('meeting-recordings.output.driver');

        return in_array($driver, [self::DRIVER_LOCAL, self::DRIVER_S3], true)
            && ($driver !== self::DRIVER_S3 || $this->hasRequiredS3Settings())
            && ($driver !== self::DRIVER_LOCAL || $this->isConsistent());
    }

    private function hasRequiredS3Settings(): bool
    {
        $s3 = (array) config('meeting-recordings.output.s3');

        return collect(['bucket', 'region', 'key', 'secret'])->every(
            fn (string $key) => is_string($s3[$key] ?? null) && ($s3[$key] ?? '') !== ''
        );
    }
}
