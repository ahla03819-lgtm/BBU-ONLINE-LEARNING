<?php

namespace App\Services\Meetings;

use App\Contracts\MeetingLifecycleProvider;
use App\Enums\MeetingProviderState;
use App\Models\Meeting;

class NoopMeetingLifecycleProvider implements MeetingLifecycleProvider
{
    public function start(Meeting $meeting, string $attemptUuid): MeetingProviderState
    {
        return MeetingProviderState::Active;
    }

    public function end(Meeting $meeting): MeetingProviderState
    {
        return MeetingProviderState::Ended;
    }

    public function inspect(Meeting $meeting): MeetingProviderState
    {
        return MeetingProviderState::Unknown;
    }
}
