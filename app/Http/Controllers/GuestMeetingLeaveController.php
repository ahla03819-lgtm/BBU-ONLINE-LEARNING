<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\LeaveGuestMeeting;
use App\Models\GuestMeetingSession;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;

class GuestMeetingLeaveController extends Controller
{
    public function store(LeaveGuestMeeting $action, GuestMeetingSessionService $sessions): JsonResponse
    {
        /** @var GuestMeetingSession $session */
        $session = request()->attributes->get('guestMeetingSession');
        $terminal = $action->handle($session);

        return response()
            ->json(['session' => $this->payload($terminal, $sessions), 'redirect_url' => $this->terminalUrl()])
            ->withCookie($this->forgetCredential($terminal));
    }

    private function payload(GuestMeetingSession $session, GuestMeetingSessionService $sessions): array
    {
        return [
            'public_uuid' => $session->public_uuid,
            'status' => $sessions->state($session),
            'terminal_url' => $this->terminalUrl(),
        ];
    }

    private function terminalUrl(): string
    {
        // A shared authenticated browser must never be sent through /login,
        // which would redirect it into Dashboard as a side effect of guest exit.
        return request()->user() ? route('guest.exit', absolute: false) : route('login', absolute: false);
    }

    private function forgetCredential(GuestMeetingSession $session): Cookie
    {
        return cookie()->forget(config('guest-meetings.cookie_name'), '/meetings/guest/sessions/'.$session->public_uuid);
    }
}
