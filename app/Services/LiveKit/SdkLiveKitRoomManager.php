<?php

namespace App\Services\LiveKit;

use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use App\Enums\MeetingProviderState;
use Throwable;
use Twirp\Error;
use Twirp\ErrorCode;

final class SdkLiveKitRoomManager implements LiveKitRoomManager
{
    public function __construct(private ?RoomServiceClient $sdkClient = null) {}

    private function client(): RoomServiceClient
    {
        return $this->sdkClient ??= new RoomServiceClient(config('livekit.url'), config('livekit.api_key'), config('livekit.api_secret'));
    }

    public function create(string $roomName, int $maxParticipants): MeetingProviderState
    {
        if (! $this->configured()) {
            return MeetingProviderState::Ended;
        }
        try {
            $this->client()->createRoom((new RoomCreateOptions)->setName($roomName)->setMaxParticipants($maxParticipants));

            return MeetingProviderState::Active;
        } catch (Error $error) {
            return $error->getErrorCode() === ErrorCode::AlreadyExists
                ? MeetingProviderState::Active
                : ($this->isDefinitive($error) ? MeetingProviderState::Ended : MeetingProviderState::Unknown);
        } catch (Throwable) {
            return MeetingProviderState::Unknown;
        }
    }

    public function delete(string $roomName): MeetingProviderState
    {
        if (! $this->configured()) {
            return MeetingProviderState::Unknown;
        }
        try {
            $this->client()->deleteRoom($roomName);

            return MeetingProviderState::Ended;
        } catch (Error $error) {
            return $error->getErrorCode() === ErrorCode::NotFound
                ? MeetingProviderState::Ended : MeetingProviderState::Unknown;
        } catch (Throwable) {
            return MeetingProviderState::Unknown;
        }
    }

    public function inspect(string $roomName): MeetingProviderState
    {
        if (! $this->configured()) {
            return MeetingProviderState::Unknown;
        }
        try {
            return count($this->client()->listRooms([$roomName])->getRooms()) > 0
                ? MeetingProviderState::Active
                : MeetingProviderState::Ended;
        } catch (Throwable) {
            return MeetingProviderState::Unknown;
        }
    }

    private function configured(): bool
    {
        return collect(['url', 'api_key', 'api_secret'])->every(fn (string $key) => is_string(config("livekit.$key")) && config("livekit.$key") !== '');
    }

    private function isDefinitive(Error $error): bool
    {
        return in_array($error->getErrorCode(), [
            ErrorCode::InvalidArgument, ErrorCode::PermissionDenied,
            ErrorCode::Unauthenticated, ErrorCode::FailedPrecondition,
        ], true);
    }
}
