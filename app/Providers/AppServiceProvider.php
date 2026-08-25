<?php

namespace App\Providers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments) {
            $messagePolicy = collect($arguments)->contains(fn ($argument) => $argument === Message::class || $argument instanceof Message);

            return $user->isActive() && $user->hasRole('Super Admin') && ! $messagePolicy ? true : null;
        });
        RateLimiter::for('messages-create', fn (Request $request) => [Limit::perMinute(20)->by($request->user()->id.'|'.$request->route('channel')), Limit::perSecond(5, 10)->by('burst|'.$request->user()->id.'|'.$request->route('channel'))]);
        RateLimiter::for('messages-mutate', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id));
        RateLimiter::for('messages-read', fn (Request $request) => Limit::perMinute(60)->by($request->user()->id));
        RateLimiter::for('broadcast-auth', fn (Request $request) => Limit::perMinute(60)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()));
    }
}
