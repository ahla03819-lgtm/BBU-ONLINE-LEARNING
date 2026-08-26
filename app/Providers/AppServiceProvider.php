<?php

namespace App\Providers;

use App\Contracts\MeetingLifecycleProvider;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Meetings\NoopMeetingLifecycleProvider;
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
        $this->app->singleton(MeetingLifecycleProvider::class, NoopMeetingLifecycleProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments) {
            $domainPolicyRequiresExplicitOverride = collect($arguments)->contains(fn ($argument) => $argument === Message::class
                || $argument instanceof Message
                || $argument instanceof MessageAttachment
                || $argument === Meeting::class
                || $argument instanceof Meeting
                || $argument === MeetingParticipant::class
                || $argument instanceof MeetingParticipant);

            return $user->isActive() && $user->hasRole('Super Admin') && ! $domainPolicyRequiresExplicitOverride ? true : null;
        });
        RateLimiter::for('messages-create', fn (Request $request) => [Limit::perMinute(20)->by($request->user()->id.'|'.$request->route('channel')), Limit::perSecond(5, 10)->by('burst|'.$request->user()->id.'|'.$request->route('channel'))]);
        RateLimiter::for('messages-mutate', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id));
        RateLimiter::for('messages-read', fn (Request $request) => Limit::perMinute(60)->by($request->user()->id));
        RateLimiter::for('broadcast-auth', fn (Request $request) => Limit::perMinute(60)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()));
        RateLimiter::for('attachments-upload', fn (Request $request) => $request->hasFile('attachments')
            ? Limit::perMinute(10)->by($request->user()->id.'|'.data_get($request->route('channel'), 'id', $request->route('channel')))
            : Limit::none());
        RateLimiter::for('attachments-download', fn (Request $request) => Limit::perMinute(120)->by($request->user()->id));
        RateLimiter::for('reactions', fn (Request $request) => Limit::perMinute(60)->by($request->user()->id.'|'.data_get($request->route('channel'), 'id', $request->route('channel'))));
    }
}
