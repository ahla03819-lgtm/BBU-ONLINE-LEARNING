<?php

namespace App\Enums;

enum MeetingScreenShareRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';

    /**
     * The approved request has been converted into a live share because the
     * provider reported the screen-share track as published. It is still
     * active, but it no longer expires: the short approval window only guards
     * the gap between approval and the student actually starting to share.
     */
    case Sharing = 'sharing';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Consumed = 'consumed';
    case Expired = 'expired';

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Approved, self::Sharing], true);
    }

    public function isSharing(): bool
    {
        return $this === self::Sharing;
    }
}
