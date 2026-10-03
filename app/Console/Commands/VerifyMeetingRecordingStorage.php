<?php

namespace App\Console\Commands;

use App\Models\MeetingRecording;
use App\Services\Recordings\MeetingRecordingFileStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reports whether recording is actually deployable in this environment.
 *
 * Object storage is the failure mode worth catching before a class needs it: a
 * half-configured bucket or a provider pointed at storage this application cannot
 * read does not fail loudly at startup. It produces recordings that sit in
 * Processing and then time out, which is discovered by a teacher rather than by a
 * deployment. This command turns that into a checkable precondition.
 */
class VerifyMeetingRecordingStorage extends Command
{
    protected $signature = 'meetings:verify-recording-storage';

    protected $description = 'Verify the meeting recording provider and storage configuration are usable';

    public function handle(MeetingRecordingFileStore $files): int
    {
        $problems = [];

        $this->line('LiveKit Egress');
        $this->report('Enabled', (bool) config('meeting-recordings.egress_enabled'));
        $this->report('Egress API URL', $this->describeEndpoint((string) config('meeting-recordings.egress_api_url')));
        if (! (bool) config('meeting-recordings.egress_enabled')) {
            $problems[] = 'Recording is disabled: LIVEKIT_EGRESS_ENABLED is false.';
        } elseif (! $this->reachable()) {
            $problems[] = 'The configured Egress endpoint did not answer.';
        }

        $this->newLine();
        $this->line('Recording output');
        $driver = (string) config('meeting-recordings.output.driver');
        $disk = $files->diskName();
        $this->report('Output driver', $driver);
        $this->report('Laravel disk', $disk);
        $this->report('Path prefix', (string) config('meeting-recordings.path_prefix'));

        if (! in_array($driver, ['local', 's3'], true)) {
            $problems[] = "Unknown RECORDING_OUTPUT_DRIVER [{$driver}]; expected local or s3.";
        }

        if ($driver === 'local') {
            // The provider would write a path on its own machine. That is only ever
            // collectable if we share its filesystem, which has to be asserted
            // explicitly rather than assumed from a hostname.
            $this->report('Shared filesystem', $this->sharedFilesystem());
            if (! $this->sharedFilesystem()) {
                $problems[] = 'RECORDING_OUTPUT_DRIVER=local is only usable when the Egress worker shares this filesystem. '
                    .'Set RECORDING_OUTPUT_SHARED_FILESYSTEM=true for a co-located Egress, or use RECORDING_OUTPUT_DRIVER=s3 '
                    .'with an object-storage bucket. Hosted Egress, such as LiveKit Cloud, never shares this filesystem.';
            } elseif ($this->isCloudDisk($disk)) {
                $problems[] = "RECORDING_OUTPUT_DRIVER=local cannot publish to the remote [{$disk}] disk.";
            }
        }

        if ($driver === 's3') {
            $s3 = (array) config('meeting-recordings.output.s3');
            foreach (['bucket', 'region', 'key', 'secret'] as $required) {
                if (! is_string($s3[$required] ?? null) || ($s3[$required] ?? '') === '') {
                    $problems[] = "Object storage output is missing [{$required}].";
                }
            }
            $this->report('Bucket', ($s3['bucket'] ?? '') !== '' ? 'configured' : 'not set');
            $this->report('Endpoint', ($s3['endpoint'] ?? '') !== '' ? 'custom endpoint set' : 'default (AWS S3)');
            $this->report('Path style', (bool) ($s3['force_path_style'] ?? false));
        }

        $this->newLine();
        $this->line('Application disk reachability');
        $probe = trim((string) config('meeting-recordings.path_prefix'), '/').'/.verify-'.getmypid();
        try {
            $writable = Storage::disk($disk)->put($probe, 'verify');
            Storage::disk($disk)->delete($probe);
            $this->report('Disk writable', (bool) $writable);
            if (! $writable) {
                $problems[] = "The [{$disk}] disk is not writable from this host.";
            }
        } catch (Throwable $error) {
            $this->report('Disk writable', 'no — '.$error->getMessage());
            $problems[] = "The [{$disk}] disk is not reachable: ".$error->getMessage();
        }

        $watchable = MeetingRecording::query()->whereNotNull('storage_path')->count();
        $this->report('Stored recordings', $watchable);

        if ($problems !== []) {
            $this->newLine();
            $this->error('Recording is not ready to use:');
            foreach ($problems as $problem) {
                $this->line('  - '.$problem);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Recording is configured and reachable.');

        return self::SUCCESS;
    }

    /**
     * Prove the Egress service is deployed and answering.
     *
     * Any HTTP response counts, including the 401 an unauthenticated probe gets: that
     * is exactly what a healthy Egress returns to a caller with no token, and it
     * distinguishes "service is not deployed" from "service answered".
     */
    private function reachable(): bool
    {
        $url = (string) config('meeting-recordings.egress_api_url');
        if ($url === '') {
            return false;
        }

        try {
            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'timeout' => 5,
                // Any status is a valid answer; PHP would otherwise warn on a non-200.
                'ignore_errors' => true,
            ]]);
            @file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];

            return $headers !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function isCloudDisk(string $disk): bool
    {
        return (string) (config("filesystems.disks.{$disk}.driver") ?? 'local') !== 'local';
    }

    private function sharedFilesystem(): bool
    {
        return (bool) config('meeting-recordings.output.shared_filesystem');
    }

    /**
     * Report a value without ever printing a credential.
     */
    private function describeEndpoint(string $url): string
    {
        if ($url === '') {
            return 'not set';
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'configured';
    }

    private function report(string $label, string|bool $value): void
    {
        $this->line(sprintf('  %-18s %s', $label, is_bool($value) ? ($value ? 'yes' : 'no') : $value));
    }
}
