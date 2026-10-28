<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureGuestMeetingSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProtectGuestInvitationResponse;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth', 'account.active', 'verified', 'throttle:broadcast-auth']])
    ->withMiddleware(function (Middleware $middleware): void {
        // This callback runs while the HTTP kernel is resolved, before Laravel
        // loads .env. Caddy is the only process that forwards tunnel traffic to
        // Laravel, and it connects over the local loopback interface. Trusting
        // only that boundary makes its X-Forwarded-* headers authoritative without
        // accepting them from the public network.
        $middleware->trustProxies(at: ['127.0.0.0/8', '::1']);

        $middleware->web(append: [HandleInertiaRequests::class, SecurityHeaders::class]);
        $middleware->validateCsrfTokens(except: ['integrations/livekit/webhook']);
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'guest.meeting-session' => EnsureGuestMeetingSession::class,
            'guest.invitation-response' => ProtectGuestInvitationResponse::class,
            'password.change-required' => RequirePasswordChange::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
