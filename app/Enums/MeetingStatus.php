<?php

namespace App\Enums;

enum MeetingStatus: string
{
    case Scheduled = 'scheduled';
    case Starting = 'starting';
    case Active = 'active';
    case Ending = 'ending';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
}
