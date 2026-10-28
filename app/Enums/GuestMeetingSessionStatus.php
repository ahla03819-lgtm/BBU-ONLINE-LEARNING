<?php

namespace App\Enums;

enum GuestMeetingSessionStatus: string
{
    case Pending = 'pending';
    case Admitted = 'admitted';
    case Rejected = 'rejected';
    case Removed = 'removed';
    case Expired = 'expired';
}
