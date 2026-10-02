<?php

namespace App\Providers;

use App\Contracts\MeetingLifecycleProvider;
use App\Models\Assignment;
use App\Models\AssignmentGrade;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionAttachment;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordRevision;
use App\Models\AttendanceRegister;
use App\Models\Conversation;
use App\Models\ConversationCall;
use App\Models\ConversationCallParticipant;
use App\Models\ConversationMember;
use App\Models\ConversationMessage;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\MeetingSeries;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LiveKit\LiveKitRoomManager;
use App\Services\LiveKit\LiveKitTokenIssuer;
use App\Services\LiveKit\LiveKitWebhookVerifier;
use App\Services\LiveKit\SdkLiveKitRoomManager;
use App\Services\LiveKit\SdkLiveKitTokenIssuer;
use App\Services\LiveKit\SdkLiveKitWebhookVerifier;
use App\Services\Meetings\LiveKitMeetingLifecycleProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LiveKitTokenIssuer::class, SdkLiveKitTokenIssuer::class);
        $this->app->singleton(LiveKitRoomManager::class, SdkLiveKitRoomManager::class);
        $this->app->singleton(LiveKitWebhookVerifier::class, SdkLiveKitWebhookVerifier::class);
        $this->app->singleton(MeetingLifecycleProvider::class, LiveKitMeetingLifecycleProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('local') && filter_var(env('FORCE_HTTPS', false), FILTER_VALIDATE_BOOL)) {
            URL::forceScheme('https');
        }

        Gate::before(function (User $user, string $ability, array $arguments) {
            $domainPolicyRequiresExplicitOverride = collect($arguments)->contains(fn ($argument) => $argument === Message::class
                || $argument instanceof Message
                || $argument instanceof MessageAttachment
                || $argument === Meeting::class
                || $argument instanceof Meeting
                || $argument === MeetingSeries::class
                || $argument instanceof MeetingSeries
                || $argument === MeetingParticipant::class
                || $argument instanceof MeetingParticipant
                || $argument === Assignment::class
                || $argument instanceof Assignment
                || $argument === AssignmentSubmission::class
                || $argument instanceof AssignmentSubmission
                || $argument === AssignmentGrade::class
                || $argument instanceof AssignmentGrade
                || $argument === AssignmentSubmissionAttachment::class
                || $argument instanceof AssignmentSubmissionAttachment
                || $argument === AttendanceRegister::class
                || $argument instanceof AttendanceRegister
                || $argument === AttendanceRecord::class
                || $argument instanceof AttendanceRecord
                || $argument === AttendanceRecordRevision::class
                || $argument instanceof AttendanceRecordRevision
                || $argument === Conversation::class
                || $argument instanceof Conversation
                || $argument === ConversationCall::class
                || $argument instanceof ConversationCall
                || $argument instanceof ConversationCallParticipant
                || $argument instanceof ConversationMember
                || $argument instanceof ConversationMessage
                || $argument === UserNotification::class
                || $argument instanceof UserNotification
                || $argument === SchoolClass::class
                || $argument instanceof SchoolClass);

            return $user->isActive() && $user->hasVerifiedEmail() && $user->hasRole('Super Admin') && ! $domainPolicyRequiresExplicitOverride ? true : null;
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
        RateLimiter::for('meeting-tokens', fn (Request $request) => [
            Limit::perMinute(12)->by($request->user()->id.'|'.data_get($request->route('meeting'), 'id', $request->route('meeting'))),
            Limit::perMinute(30)->by('ip|'.$request->ip()),
        ]);
        RateLimiter::for('livekit-webhooks', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));
        RateLimiter::for('meeting-participant-removals', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id.'|'.data_get($request->route('meeting'), 'id', $request->route('meeting'))));
        RateLimiter::for('meeting-screen-share-requests', fn (Request $request) => Limit::perMinute(20)->by($request->user()->id.'|'.data_get($request->route('meeting'), 'id', $request->route('meeting'))));
        RateLimiter::for('meeting-lifecycle', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id.'|'.data_get($request->route('meeting'), 'id', $request->route('meeting'))));
        RateLimiter::for('class-join-code', fn (Request $request) => [
            Limit::perMinute(8)->by('user|'.$request->user()->id),
            Limit::perMinute(20)->by('ip|'.$request->ip()),
        ]);
    }
}
