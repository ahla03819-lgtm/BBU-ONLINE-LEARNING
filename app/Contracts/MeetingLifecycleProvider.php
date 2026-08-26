<?php

namespace App\Contracts;

use App\Enums\MeetingProviderState;
use App\Models\Meeting;

interface MeetingLifecycleProvider
{
    public function start(Meeting $meeting, string $attemptUuid): MeetingProviderState;

    public function end(Meeting $meeting): MeetingProviderState;

    public function inspect(Meeting $meeting): MeetingProviderState;
}
