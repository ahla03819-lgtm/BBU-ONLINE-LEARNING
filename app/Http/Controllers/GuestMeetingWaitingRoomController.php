<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\LeaveGuestMeeting;
use App\Models\GuestMeetingSession;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class GuestMeetingWaitingRoomController extends Controller
{
    public function show(GuestMeetingSessionService $sessions): Response
    {
        /** @var GuestMeetingSession $session */
        $session = request()->attributes->get('guestMeetingSession');
        $session->loadMissing('meeting');

        return Inertia::render('GuestMeetings/WaitingRoom', [
            'session' => $this->payload($session, $sessions),
            'meeting' => ['title' => $session->meeting?->title],
            'status_url' => route('guest.sessions.waiting-status', ['guestSession' => $session->public_uuid], absolute: false),
            'leave_url' => route('guest.sessions.leave', ['guestSession' => $session->public_uuid], absolute: false),
            'poll_interval_ms' => (int) config('guest-meetings.poll_interval_ms'),
        ]);
    }

    public function status(GuestMeetingSessionService $sessions, LeaveGuestMeeting $leave): JsonResponse
    {
        /** @var GuestMeetingSession $session */
        $session = request()->attributes->get('guestMeetingSession');
        $session = $leave->enforceTerminalState($session);

        $response = response()->json(['session' => $this->payload($session, $sessions)]);
        if ($this->isTerminal($sessions->state($session))) {
            $response->withCookie($this->forgetCredential($session));
        }

        return $response;
    }

    public function destroy(LeaveGuestMeeting $leave, GuestMeetingSessionService $sessions): JsonResponse
    {
        /** @var GuestMeetingSession $session */
        $session = request()->attributes->get('guestMeetingSession');
        $cancelled = $leave->handle($session);

        return response()
            ->json(['session' => $this->payload($cancelled, $sessions), 'redirect_url' => $this->terminalUrl()])
            ->withCookie($this->forgetCredential($cancelled));
    }

    private function payload(GuestMeetingSession $session, GuestMeetingSessionService $sessions): array
    {
        return [
            'public_uuid' => $session->public_uuid,
            'display_name' => $session->display_name_snapshot,
            'status' => $sessions->state($session),
            'requested_at' => $session->requested_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'can_leave' => $session->status->value === 'pending' && $session->invalidated_at === null,
            'can_join_room' => $sessions->canJoinActiveMeeting($session),
            'room_url' => $sessions->canJoinActiveMeeting($session)
                ? route('guest.sessions.room', ['guestSession' => $session->public_uuid], absolute: false)
                : null,
            'terminal_url' => $this->isTerminal($sessions->state($session)) ? $this->terminalUrl() : null,
        ];
    }

    private function isTerminal(string $state): bool
    {
        return in_array($state, ['rejected', 'removed', 'expired', 'unavailable', 'meeting_ended'], true);
    }

    private function terminalUrl(): string
    {
        return request()->user() ? route('guest.exit', absolute: false) : route('login', absolute: false);
    }

    private function forgetCredential(GuestMeetingSession $session): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie()->forget(config('guest-meetings.cookie_name'), '/meetings/guest/sessions/'.$session->public_uuid);
    }
}
