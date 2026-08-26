<?php

namespace App\Enums;

enum MeetingProviderState: string
{
    case Active = 'active';
    case Ended = 'ended';
    case Unknown = 'unknown';
}
