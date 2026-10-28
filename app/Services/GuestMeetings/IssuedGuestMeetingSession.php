<?php

namespace App\Services\GuestMeetings;

use App\Models\GuestMeetingSession;

final readonly class IssuedGuestMeetingSession
{
    public function __construct(
        public GuestMeetingSession $session,
        public string $credential,
    ) {}
}
