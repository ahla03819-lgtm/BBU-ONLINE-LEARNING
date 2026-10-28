<?php

namespace App\Http\Middleware;

use App\Services\GuestMeetings\GuestMeetingSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestMeetingSession
{
    public function __construct(private GuestMeetingSessionService $sessions) {}

    public function handle(Request $request, Closure $next, string $mode = 'usable'): Response
    {
        $isPolling = in_array($mode, ['poll', 'poll-state'], true);
        // This check intentionally precedes session lookup. Invalid UUIDs and
        // absent/forged cookies are therefore bounded by IP and cannot mint
        // arbitrary client-selected rate-limit identities.
        if ($isPolling) {
            $this->hit($this->fallbackKey($request), 60);
        }

        $publicUuid = (string) $request->route('guestSession');
        $session = in_array($mode, ['state', 'poll-state'], true)
            ? $this->sessions->authenticateOwner($publicUuid, $request->cookie(config('guest-meetings.cookie_name')))
            : $this->sessions->authenticate($publicUuid, $request->cookie(config('guest-meetings.cookie_name')));
        if (! $session) {
            abort(404);
        }

        $request->attributes->set('guestMeetingSession', $session);
        if ($isPolling) {
            // $session is server-resolved and credential-validated above. Hash
            // its opaque public UUID before using it in the cache key.
            $this->hit($this->sessionKey($request, $session->public_uuid), 12);
        }

        return $next($request);
    }

    private function hit(string $key, int $maxAttempts): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            abort(429);
        }

        RateLimiter::hit($key, 60);
    }

    private function fallbackKey(Request $request): string
    {
        return 'guest-status-fallback|'.hash('sha256', $request->ip());
    }

    private function sessionKey(Request $request, string $publicUuid): string
    {
        return 'guest-status|'.hash('sha256', $publicUuid.'|'.$request->ip());
    }
}
