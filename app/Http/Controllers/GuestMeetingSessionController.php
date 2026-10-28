<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuestMeetings\CreateGuestMeetingSessionRequest;
use App\Models\GuestMeetingInvitation;
use App\Models\GuestMeetingSession;
use App\Services\GuestMeetings\GuestMeetingInvitationService;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Cookie;

class GuestMeetingSessionController extends Controller
{
    public function show(Request $request, string $token, GuestMeetingInvitationService $invitations): JsonResponse|Response
    {
        try {
            $invitation = $invitations->resolve($token);
        } catch (ModelNotFoundException) {
            if (! $this->isInertiaVisit($request) && $request->wantsJson()) {
                return response()->json(['invitation' => ['available' => false]], 404);
            }

            return Inertia::render('GuestMeetings/Join', ['invitation' => ['available' => false]]);
        }

        if (! $this->isInertiaVisit($request) && $request->wantsJson()) {
            return response()->json([
                'invitation' => $this->invitationPayload($invitation),
            ]);
        }

        return Inertia::render('GuestMeetings/Join', [
            'invitation' => [...$this->invitationPayload($invitation), 'request_url' => route('guest.sessions.store', ['token' => $token], absolute: false)],
        ]);
    }

    public function store(CreateGuestMeetingSessionRequest $request, string $token, GuestMeetingInvitationService $invitations, GuestMeetingSessionService $sessions): JsonResponse|RedirectResponse
    {
        $invitation = $invitations->resolve($token);
        $retryCredential = $request->cookie(config('guest-meetings.retry_cookie_name'));
        $issued = $sessions->create($invitation, $request->validated('display_name'), $retryCredential);

        if (! $this->isInertiaVisit($request) && $request->wantsJson()) {
            return response()
                ->json(['session' => $this->payload($issued->session)], 201)
                ->withCookie($this->cookie($issued->session, $issued->credential))
                ->withCookie($this->retryCookie($token, $issued->credential, $issued->session));
        }

        return redirect()
            ->route('guest.sessions.waiting', ['guestSession' => $issued->session->public_uuid])
            ->withCookie($this->cookie($issued->session, $issued->credential))
            ->withCookie($this->retryCookie($token, $issued->credential, $issued->session));
    }

    public function status(): JsonResponse
    {
        /** @var GuestMeetingSession $session */
        $session = request()->attributes->get('guestMeetingSession');

        return response()->json(['session' => $this->payload($session)]);
    }

    private function invitationPayload(GuestMeetingInvitation $invitation): array
    {
        return [
            'available' => true,
            'meeting_title' => $invitation->meeting?->title,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ];
    }

    private function payload(GuestMeetingSession $session): array
    {
        return [
            'public_uuid' => $session->public_uuid,
            'display_name' => $session->display_name_snapshot,
            'status' => $session->status->value,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];
    }

    private function cookie(GuestMeetingSession $session, string $credential): Cookie
    {
        $minutes = max(1, now()->diffInMinutes($session->expires_at, false));

        return cookie(
            config('guest-meetings.cookie_name'),
            $credential,
            $minutes,
            '/meetings/guest/sessions/'.$session->public_uuid,
            null,
            (bool) config('guest-meetings.cookie_secure'),
            true,
            false,
            config('guest-meetings.cookie_same_site'),
        );
    }

    private function retryCookie(string $token, string $credential, GuestMeetingSession $session): Cookie
    {
        $minutes = max(1, now()->diffInMinutes($session->expires_at, false));

        return cookie(
            config('guest-meetings.retry_cookie_name'),
            $credential,
            $minutes,
            '/meetings/guest/'.$token.'/sessions',
            null,
            (bool) config('guest-meetings.cookie_secure'),
            true,
            false,
            config('guest-meetings.cookie_same_site'),
        );
    }

    private function isInertiaVisit(Request $request): bool
    {
        return $request->header('X-Inertia') === 'true';
    }
}
