<?php

namespace App\Enums;

enum MeetingParticipantRole: string
{
    case Host = 'host';
    case Participant = 'participant';
    case Guest = 'guest';
}
