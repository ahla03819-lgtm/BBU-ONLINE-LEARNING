<?php

namespace App\Services\LiveKit;

use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use App\Enums\MeetingMicrophoneMuteResult;
use App\Enums\MeetingProviderState;
use Illuminate\Support\Facades\Log;
use Livekit\TrackSource;
use Livekit\TrackType;
use Throwable;
use Twirp\Error;
use Twirp\ErrorCode;

final class SdkLiveKitRoomManager implements LiveKitRoomManager
{
    public function __construct(private ?RoomServiceClient $sdkClient = null) {}

    private function client(): RoomServiceClient
    {
        return $this->sdkClient ??= new RoomServiceClient(config('livekit.api_url'), config('livekit.api_key'), config('livekit.api_secret'));
    }

    public function create(string $roomName, int $maxParticipants): MeetingProviderState
    {
        if (! $this->configured()) {
            return $this->configurationFailure('create');
        }
        try {
            $this->client()->createRoom((new RoomCreateOptions)->setName($roomName)->setMaxParticipants($maxParticipants));

            return MeetingProviderState::Active;
        } catch (Error $error) {
            if ($error->getErrorCode() !== ErrorCode::AlreadyExists) {
                $this->logFailure('create', $error);
            }

            return $error->getErrorCode() === ErrorCode::AlreadyExists
                ? MeetingProviderState::Active
                : ($this->isDefinitive($error) ? MeetingProviderState::Ended : MeetingProviderState::Unknown);
        } catch (Throwable $error) {
            $this->logFailure('create', $error);

            return MeetingProviderState::Unknown;
        }
    }

    public function delete(string $roomName): MeetingProviderState
    {
        if (! $this->configured()) {
            return $this->configurationFailure('delete');
        }
        try {
            $this->client()->deleteRoom($roomName);

            return MeetingProviderState::Ended;
        } catch (Error $error) {
            if ($error->getErrorCode() !== ErrorCode::NotFound) {
                $this->logFailure('delete', $error);
            }

            return $error->getErrorCode() === ErrorCode::NotFound
                ? MeetingProviderState::Ended : MeetingProviderState::Unknown;
        } catch (Throwable $error) {
            $this->logFailure('delete', $error);

            return MeetingProviderState::Unknown;
        }
    }

    public function inspect(string $roomName): MeetingProviderState
    {
        if (! $this->configured()) {
            return $this->configurationFailure('inspect');
        }
        try {
            return count($this->client()->listRooms([$roomName])->getRooms()) > 0
                ? MeetingProviderState::Active
                : MeetingProviderState::Ended;
        } catch (Throwable $error) {
            $this->logFailure('inspect', $error);

            return MeetingProviderState::Unknown;
        }
    }

    public function removeParticipant(string $roomName, string $identity): MeetingProviderState
    {
        if (! $this->configured()) {
            return $this->configurationFailure('remove_participant');
        }
        try {
            $this->client()->removeParticipant($roomName, $identity);

            return MeetingProviderState::Ended;
        } catch (Error $error) {
            if ($error->getErrorCode() !== ErrorCode::NotFound) {
                $this->logFailure('remove_participant', $error);
            }

            return $error->getErrorCode() === ErrorCode::NotFound
                ? MeetingProviderState::Ended : MeetingProviderState::Unknown;
        } catch (Throwable $error) {
            $this->logFailure('remove_participant', $error);

            return MeetingProviderState::Unknown;
        }
    }

    public function muteParticipantMicrophone(string $roomName, string $identity): MeetingMicrophoneMuteResult
    {
        if (! $this->configured()) {
            $this->configurationFailure('mute_participant_microphone');

            return MeetingMicrophoneMuteResult::ProviderFailure;
        }

        try {
            $participant = $this->client()->getParticipant($roomName, $identity);
            $microphones = collect(iterator_to_array($participant->getTracks()))
                ->filter(fn ($track) => $track->getType() === TrackType::AUDIO
                    && $track->getSource() === TrackSource::MICROPHONE);

            if ($microphones->isEmpty()) {
                return MeetingMicrophoneMuteResult::NoActiveMicrophone;
            }

            $microphone = $microphones->first(fn ($track) => ! $track->getMuted());
            if (! $microphone) {
                return MeetingMicrophoneMuteResult::AlreadyMuted;
            }

            // Moderation is deliberately one-way: this service never sends muted=false.
            $this->client()->mutePublishedTrack($roomName, $identity, $microphone->getSid(), true);

            return MeetingMicrophoneMuteResult::Muted;
        } catch (Error $error) {
            if ($error->getErrorCode() !== ErrorCode::NotFound) {
                $this->logFailure('mute_participant_microphone', $error);
            }

            return $error->getErrorCode() === ErrorCode::NotFound
                ? MeetingMicrophoneMuteResult::ParticipantNotPresent
                : MeetingMicrophoneMuteResult::ProviderFailure;
        } catch (Throwable $error) {
            $this->logFailure('mute_participant_microphone', $error);

            return MeetingMicrophoneMuteResult::ProviderFailure;
        }
    }

    private function configured(): bool
    {
        $apiUrl = config('livekit.api_url');

        return is_string($apiUrl)
            && filter_var($apiUrl, FILTER_VALIDATE_URL) !== false
            && parse_url($apiUrl, PHP_URL_SCHEME) === 'https'
            && collect(['api_key', 'api_secret'])->every(fn (string $key) => is_string(config("livekit.$key")) && config("livekit.$key") !== '');
    }

    private function configurationFailure(string $operation): MeetingProviderState
    {
        Log::warning('LiveKit Room Service is not safely configured.', ['operation' => $operation]);

        return MeetingProviderState::Unknown;
    }

    private function logFailure(string $operation, Throwable $error): void
    {
        Log::warning('LiveKit Room Service operation failed.', [
            'operation' => $operation,
            'exception_class' => $error::class,
            'provider_code' => $error instanceof Error ? $error->getErrorCode() : null,
        ]);
    }

    private function isDefinitive(Error $error): bool
    {
        return in_array($error->getErrorCode(), [
            ErrorCode::InvalidArgument, ErrorCode::PermissionDenied,
            ErrorCode::Unauthenticated, ErrorCode::FailedPrecondition,
        ], true);
    }
}
