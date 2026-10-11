<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();
        if (! str_ends_with(mb_strtolower($request->string('email')->trim()->value()), '@bbu.edu.kh') || ! Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            RateLimiter::hit($request->throttleKey(), 60);
            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }
        if (! $request->user()->isActive() || $request->user()->approved_at === null) {
            Auth::logout();
            RateLimiter::hit($request->throttleKey(), 60);
            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }
        RateLimiter::clear($request->throttleKey());
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        return $this->redirectToIntended($request);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Consume the guest URL only when it remains on this application's host.
     *
     * A session created before proxy scheme detection was fixed can contain the
     * same current host over HTTP. Upgrade only that narrow, already-safe case;
     * never rewrite or follow a foreign absolute URL from session state.
     */
    private function redirectToIntended(Request $request): RedirectResponse
    {
        $intended = $request->session()->get('url.intended');
        $safe = $this->safeIntendedUrl($request, $intended);

        if ($safe === null) {
            $request->session()->forget('url.intended');

            return redirect()->route('dashboard');
        }

        if ($safe !== $intended) {
            $request->session()->put('url.intended', $safe);
        }

        return redirect()->intended(route('dashboard'));
    }

    private function safeIntendedUrl(Request $request, mixed $intended): ?string
    {
        if (! is_string($intended) || $intended === '') {
            return null;
        }

        $url = parse_url($intended);
        if ($url === false
            || ! isset($url['scheme'], $url['host'])
            || ! in_array(strtolower($url['scheme']), ['http', 'https'], true)
            || isset($url['user'], $url['pass'])
            || strcasecmp($url['host'], $request->getHost()) !== 0
            || (isset($url['port']) && (int) $url['port'] !== (int) $request->getPort())) {
            return null;
        }

        if ($request->isSecure() && strtolower($url['scheme']) === 'http') {
            return preg_replace('/\Ahttp:/i', 'https:', $intended);
        }

        return $intended;
    }
}
