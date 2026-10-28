<?php

namespace App\Http\Controllers;

use App\Models\GuestMeetingSession;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use Inertia\Inertia;
use Inertia\Response;

class GuestMeetingRoomController extends Controller
{
    public function show(GuestMeetingSessionService $sessions): Response
    {
        /** @var GuestMeetingSession $session */
        $session = request()->attributes->get('guestMeetingSession');
        $session->loadMissing('meeting');
        abort_unless($sessions->canJoinActiveMeeting($session), 404);

        return Inertia::render('GuestMeetings/Room', [
            'session' => [
                'display_name' => $session->display_name_snapshot,
                'expires_at' => $session->expires_at?->toIso8601String(),
            ],
            'meeting' => ['title' => $session->meeting?->title],
            'token_url' => route('guest.sessions.token', ['guestSession' => $session->public_uuid], absolute: false),
            'waiting_url' => route('guest.sessions.waiting', ['guestSession' => $session->public_uuid], absolute: false),
            'status_url' => route('guest.sessions.waiting-status', ['guestSession' => $session->public_uuid], absolute: false),
            'leave_url' => route('guest.sessions.leave', ['guestSession' => $session->public_uuid], absolute: false),
            'poll_interval_ms' => (int) config('guest-meetings.poll_interval_ms'),
        ]);
    }
}
