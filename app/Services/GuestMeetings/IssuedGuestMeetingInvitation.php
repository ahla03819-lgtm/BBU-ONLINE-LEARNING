<?php

namespace App\Services\GuestMeetings;

use App\Models\GuestMeetingInvitation;

final readonly class IssuedGuestMeetingInvitation
{
    public function __construct(
        public GuestMeetingInvitation $invitation,
        public string $token,
    ) {}
}
