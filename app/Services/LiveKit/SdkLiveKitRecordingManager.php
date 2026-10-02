<?php

namespace App\Services\LiveKit;

use Agence104\LiveKit\EgressServiceClient;
use App\Enums\MeetingRecordingProviderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Livekit\EncodedFileOutput;
use Livekit\EncodedFileType;
use RuntimeException;
use Throwable;
use Twirp\Error;
use Twirp\ErrorCode;

final class SdkLiveKitRecordingManager implements LiveKitRecordingManager
{
    public function __construct(private ?EgressServiceClient $sdkClient = null) {}

    private function client(): EgressServiceClient
    {
        return $this->sdkClient ??= new EgressServiceClient(
            config('meeting-recordings.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );
    }

    public function startRoomCompositeRecording(string $roomName, string $outputPath, string $layout): LiveKitRecordingStart
    {
        if (! $this->configured()) {
            $this->logFailure('start_room_composite_recording', new RuntimeException('LiveKit Egress is not safely configured.'));

            return new LiveKitRecordingStart(MeetingRecordingProviderStatus::Unknown);
        }

        try {
            $output = new EncodedFileOutput([
                'file_type' => EncodedFileType::MP4,
                'filepath' => $outputPath,
                // A manifest would add an HLS playlist this application never
                // serves, and would leave the provider holding its own index of the
                // finished file.
                'disable_manifest' => true,
            ]);

            $info = $this->client()->startRoomCompositeEgress($roomName, $layout, $output);
            $egressId = (string) $info->getEgressId();
            if ($egressId === '') {
                return new LiveKitRecordingStart(MeetingRecordingProviderStatus::Unknown);
            }

            return new LiveKitRecordingStart(
                EgressInfoMapper::status($info),
                $egressId,
                $info->getError() ?: null,
            );
        } catch (Throwable $error) {
            $this->logFailure('start_room_composite_recording', $error);

            return new LiveKitRecordingStart($this->mapError($error));
        }
    }

    public function stopRecording(string $egressId): LiveKitRecordingOutcome
    {
        if (! $this->configured()) {
            $this->logFailure('stop_recording', new RuntimeException('LiveKit Egress is not safely configured.'));

            return new LiveKitRecordingOutcome(MeetingRecordingProviderStatus::Unknown);
        }

        try {
            return new LiveKitRecordingOutcome(
                MeetingRecordingProviderStatus::Complete,
                EgressInfoMapper::files($this->client()->stopEgress($egressId)),
            );
        } catch (Error $error) {
            if ($error->getErrorCode() === ErrorCode::NotFound) {
                // Nothing is running for this egress, which is exactly the state the
                // stop was asking for. Whatever output the provider did manage to
                // write is recovered separately by inspecting the egress.
                return new LiveKitRecordingOutcome(MeetingRecordingProviderStatus::Complete);
            }
            $this->logFailure('stop_recording', $error);

            return new LiveKitRecordingOutcome($this->mapError($error));
        } catch (Throwable $error) {
            $this->logFailure('stop_recording', $error);

            return new LiveKitRecordingOutcome(MeetingRecordingProviderStatus::Unknown);
        }
    }

    public function inspectRecording(string $egressId): ?LiveKitRecordingStatus
    {
        if (! $this->configured()) {
            $this->logFailure('inspect_recording', new RuntimeException('LiveKit Egress is not safely configured.'));

            return null;
        }

        try {
            $items = iterator_to_array($this->client()->listEgress('', $egressId)->getItems());
            if ($items === []) {
                return null;
            }
            $info = $items[0];
            $startedAt = (int) $info->getStartedAt();
            $endedAt = (int) $info->getEndedAt();

            return new LiveKitRecordingStatus(
                EgressInfoMapper::status($info),
                $egressId,
                EgressInfoMapper::files($info),
                $startedAt > 0 ? CarbonImmutable::createFromTimestamp($startedAt) : null,
                $endedAt > 0 ? CarbonImmutable::createFromTimestamp($endedAt) : null,
                $info->getError() ?: null,
            );
        } catch (Throwable $error) {
            $this->logFailure('inspect_recording', $error);

            return null;
        }
    }

    private function mapError(Throwable $error): MeetingRecordingProviderStatus
    {
        if ($error instanceof Error && in_array($error->getErrorCode(), [
            ErrorCode::InvalidArgument, ErrorCode::PermissionDenied,
            ErrorCode::Unauthenticated, ErrorCode::FailedPrecondition,
        ], true)) {
            return MeetingRecordingProviderStatus::Failed;
        }

        return MeetingRecordingProviderStatus::Unknown;
    }

    private function configured(): bool
    {
        if (! config('meeting-recordings.egress_enabled')) {
            return false;
        }

        $url = config('meeting-recordings.egress_api_url');

        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && collect(['api_key', 'api_secret'])->every(fn (string $key) => is_string(config("livekit.$key")) && config("livekit.$key") !== '');
    }

    private function logFailure(string $operation, Throwable $error): void
    {
        Log::warning('LiveKit Egress operation failed.', [
            'operation' => $operation,
            'exception_class' => $error::class,
            'provider_code' => $error instanceof Error ? $error->getErrorCode() : null,
        ]);
    }
}
