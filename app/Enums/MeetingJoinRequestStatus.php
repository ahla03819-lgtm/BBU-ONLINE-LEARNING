<?php

namespace App\Enums;

enum MeetingJoinRequestStatus: string
{
    case Pending = 'pending';
    case Admitted = 'admitted';
    case Denied = 'denied';
    case Cancelled = 'cancelled';
}
