<?php

namespace App\Services\Meetings;

use App\Contracts\MeetingLifecycleProvider;
use App\Enums\MeetingProviderState;
use App\Models\Meeting;
use App\Services\LiveKit\LiveKitRoomManager;

final class LiveKitMeetingLifecycleProvider implements MeetingLifecycleProvider
{
    public function __construct(private LiveKitRoomManager $rooms) {}

    public function start(Meeting $meeting, string $attemptUuid): MeetingProviderState
    {
        return $this->rooms->create($meeting->livekit_room_name, $meeting->max_participants);
    }

    public function end(Meeting $meeting): MeetingProviderState
    {
        return $this->rooms->delete($meeting->livekit_room_name);
    }

    public function inspect(Meeting $meeting): MeetingProviderState
    {
        return $this->rooms->inspect($meeting->livekit_room_name);
    }
}
