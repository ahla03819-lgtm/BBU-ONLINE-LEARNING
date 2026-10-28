<?php

namespace App\Actions\Meetings;

use App\Models\GuestMeetingSession;
use App\Services\GuestMeetings\GuestMeetingSessionService;

/** Revokes a guest server-side, then attempts provider removal after commit. */
final class LeaveGuestMeeting
{
    public function __construct(
        private GuestMeetingSessionService $sessions,
    ) {}

    public function handle(GuestMeetingSession $session): GuestMeetingSession
    {
        return $this->sessions->leave($session)['session'];
    }

    /** Applies expiry/terminal-meeting revocation when the owning guest polls. */
    public function enforceTerminalState(GuestMeetingSession $session): GuestMeetingSession
    {
        return $this->sessions->enforceTerminalState($session)['session'];
    }
}
