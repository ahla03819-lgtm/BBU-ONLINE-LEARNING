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

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
