<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingMicrophoneMuteResult;
use App\Enums\MeetingProviderState;

interface LiveKitRoomManager
{
    public function create(string $roomName, int $maxParticipants): MeetingProviderState;

    public function delete(string $roomName): MeetingProviderState;

    public function inspect(string $roomName): MeetingProviderState;

    public function removeParticipant(string $roomName, string $identity): MeetingProviderState;

    public function muteParticipantMicrophone(string $roomName, string $identity): MeetingMicrophoneMuteResult;
}
