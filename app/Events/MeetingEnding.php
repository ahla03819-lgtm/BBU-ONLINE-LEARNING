<?php

namespace App\Events;

use App\Models\Meeting;

class MeetingEnding extends MeetingLifecycleChanged
{
    public function __construct(Meeting $meeting)
    {
        parent::__construct($meeting, 'ending');
    }
}
