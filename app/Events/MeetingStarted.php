<?php

namespace App\Events;

use App\Models\Meeting;

class MeetingStarted extends MeetingLifecycleChanged
{
    public function __construct(Meeting $meeting)
    {
        parent::__construct($meeting, 'started');
    }
}
