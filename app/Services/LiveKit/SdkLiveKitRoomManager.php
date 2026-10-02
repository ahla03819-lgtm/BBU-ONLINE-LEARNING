<?php

namespace App\Services\LiveKit;

use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use App\Enums\MeetingMicrophoneMuteResult;
use App\Enums\MeetingProviderState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Livekit\ParticipantPermission;
use Livekit\TrackSource;
use Livekit\TrackType;
use RuntimeException;
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

    public function setParticipantScreenSharePermission(string $roomName, string $identity, bool $allowed): MeetingProviderState
    {
        if (! $this->configured()) {
            return $this->configurationFailure('screen_share_permission');
        }

        try {
            $participant = $this->client()->getParticipant($roomName, $identity);
            $current = $participant->getPermission();
            $sources = collect(iterator_to_array($current->getCanPublishSources()))
                ->reject(fn (int $source) => in_array($source, [TrackSource::SCREEN_SHARE, TrackSource::SCREEN_SHARE_AUDIO], true));
            if ($allowed) {
                $sources->push(TrackSource::SCREEN_SHARE, TrackSource::SCREEN_SHARE_AUDIO);
            }
            $permission = new ParticipantPermission([
                'can_subscribe' => $current->getCanSubscribe(),
                'can_publish' => $current->getCanPublish(),
                'can_publish_data' => $current->getCanPublishData(),
                'can_publish_sources' => $sources->unique()->values()->all(),
                'hidden' => $current->getHidden(),
                'recorder' => $current->getRecorder(),
                'can_update_metadata' => $current->getCanUpdateMetadata(),
                'agent' => $current->getAgent(),
                'can_subscribe_metrics' => $current->getCanSubscribeMetrics(),
            ]);
            $this->client()->updateParticipant($roomName, $identity, null, $permission);

            return MeetingProviderState::Active;
        } catch (Error $error) {
            if ($error->getErrorCode() !== ErrorCode::NotFound) {
                $this->logFailure('screen_share_permission', $error);
            }

            return $error->getErrorCode() === ErrorCode::NotFound
                ? MeetingProviderState::Ended : MeetingProviderState::Unknown;
        } catch (Throwable $error) {
            $this->logFailure('screen_share_permission', $error);

            return MeetingProviderState::Unknown;
        }
    }

    public function participantPresence(string $roomName, string $identity): LiveKitParticipantPresence
    {
        if (! $this->configured()) {
            $this->logFailure('participant_presence', new RuntimeException('LiveKit is not configured.'));

            return new LiveKitParticipantPresence(MeetingProviderState::Unknown);
        }

        try {
            // getParticipant matches the exact identity, so presence can never be
            // attributed to a different participant in the same room.
            $participant = $this->client()->getParticipant($roomName, $identity);
            $sid = $participant->getSid();
            if ($sid === '') {
                return new LiveKitParticipantPresence(MeetingProviderState::Unknown);
            }
            $joinedAtMs = (int) $participant->getJoinedAtMs();
            if ($joinedAtMs <= 0) {
                $seconds = (int) $participant->getJoinedAt();
                $joinedAtMs = $seconds > 0 ? $seconds * 1000 : 0;
            }

            return new LiveKitParticipantPresence(
                MeetingProviderState::Active,
                $sid,
                $joinedAtMs > 0
                    ? CarbonImmutable::createFromTimestampMs($joinedAtMs)
                    : null,
            );
        } catch (Error $error) {
            if ($error->getErrorCode() === ErrorCode::NotFound) {
                return new LiveKitParticipantPresence(MeetingProviderState::Ended);
            }
            $this->logFailure('participant_presence', $error);

            return new LiveKitParticipantPresence(MeetingProviderState::Unknown);
        } catch (Throwable $error) {
            $this->logFailure('participant_presence', $error);

            return new LiveKitParticipantPresence(MeetingProviderState::Unknown);
        }
    }

    public function screenShareState(string $roomName, string $identity): LiveKitScreenShareState
    {
        if (! $this->configured()) {
            $this->logFailure('screen_share_state', new RuntimeException('LiveKit is not configured.'));

            return new LiveKitScreenShareState(MeetingProviderState::Unknown);
        }

        try {
            $participant = $this->client()->getParticipant($roomName, $identity);
            $videoSid = null;
            $audioSids = [];
            foreach ($participant->getTracks() as $track) {
                // Only the provider's own track records are trusted here. The
                // canonical screen share is a SCREEN_SHARE VIDEO publication;
                // its audio companion is reported but never treated as the share.
                if ((int) $track->getSource() === TrackSource::SCREEN_SHARE && (int) $track->getType() === TrackType::VIDEO) {
                    $videoSid ??= (string) $track->getSid();
                } elseif ((int) $track->getSource() === TrackSource::SCREEN_SHARE_AUDIO) {
                    $audioSids[] = (string) $track->getSid();
                }
            }

            return new LiveKitScreenShareState(
                MeetingProviderState::Active,
                $videoSid !== '' ? $videoSid : null,
                $audioSids,
            );
        } catch (Error $error) {
            if ($error->getErrorCode() === ErrorCode::NotFound) {
                return new LiveKitScreenShareState(MeetingProviderState::Ended);
            }
            $this->logFailure('screen_share_state', $error);

            return new LiveKitScreenShareState(MeetingProviderState::Unknown);
        } catch (Throwable $error) {
            $this->logFailure('screen_share_state', $error);

            return new LiveKitScreenShareState(MeetingProviderState::Unknown);
        }
    }

    public function mutePublishedTrack(string $roomName, string $identity, string $trackSid): MeetingProviderState
    {
        if (! $this->configured()) {
            return $this->configurationFailure('mute_published_track');
        }

        try {
            // Moderation is deliberately one-way: this service never sends muted=false.
            $this->client()->mutePublishedTrack($roomName, $identity, $trackSid, true);

            return MeetingProviderState::Active;
        } catch (Error $error) {
            if ($error->getErrorCode() !== ErrorCode::NotFound) {
                $this->logFailure('mute_published_track', $error);
            }

            return $error->getErrorCode() === ErrorCode::NotFound
                ? MeetingProviderState::Ended : MeetingProviderState::Unknown;
        } catch (Throwable $error) {
            $this->logFailure('mute_published_track', $error);

            return MeetingProviderState::Unknown;
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
