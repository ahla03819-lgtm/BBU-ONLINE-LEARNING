<?php

namespace App\Actions\Meetings;

use App\Contracts\MeetingLifecycleProvider;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingStatus;
use App\Events\MeetingStarted;
use App\Models\Meeting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StartMeeting
{
    public function __construct(private MeetingLifecycleProvider $provider, private AuditLogger $audit) {}

    public function handle(User $actor, Meeting $meeting): Meeting
    {
        Gate::forUser($actor)->authorize('start', $meeting);

        [$authoritative, $attemptUuid, $invokeProvider] = DB::transaction(function () use ($actor, $meeting) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            Gate::forUser($actor)->authorize('start', $locked);
            if ($locked->status === MeetingStatus::Active || $locked->status === MeetingStatus::Starting) {
                return [$locked, $locked->start_attempt_uuid, false];
            }
            if ($locked->status !== MeetingStatus::Scheduled) {
                throw ValidationException::withMessages(['meeting' => 'This meeting cannot be started from its current state.']);
            }

            $before = $locked->only('status', 'lifecycle_version');
            $attemptUuid = (string) Str::uuid();
            $locked->update([
                'status' => MeetingStatus::Starting,
                'lifecycle_version' => $locked->lifecycle_version + 1,
                'start_attempt_uuid' => $attemptUuid,
                'last_provider_error' => null,
            ]);
            $this->audit->log('meeting.start-requested', $locked, $before, $locked->only('status', 'lifecycle_version'));

            return [$locked, $attemptUuid, true];
        });

        if (! $invokeProvider) {
            return $authoritative;
        }

        try {
            $state = $this->provider->start($authoritative, $attemptUuid);
        } catch (Throwable) {
            return $this->recordFailure($authoritative, $attemptUuid, 'Meeting provider start failed.');
        }

        if ($state !== MeetingProviderState::Active) {
            if ($state === MeetingProviderState::Ended) {
                return $this->recordDefinitiveFailure($authoritative, $attemptUuid);
            }

            return $authoritative->fresh();
        }

        return $this->complete($authoritative, $attemptUuid);
    }

    public function complete(Meeting $meeting, string $attemptUuid): Meeting
    {
        return DB::transaction(function () use ($meeting, $attemptUuid) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            if ($locked->status === MeetingStatus::Active) {
                return $locked;
            }
            if ($locked->status !== MeetingStatus::Starting || ! hash_equals((string) $locked->start_attempt_uuid, $attemptUuid)) {
                return $locked;
            }

            $before = $locked->only('status', 'lifecycle_version');
            $locked->update([
                'status' => MeetingStatus::Active,
                'actual_start_at' => $locked->actual_start_at ?? now(),
                'lifecycle_version' => $locked->lifecycle_version + 1,
                'last_provider_error' => null,
            ]);
            $this->audit->log('meeting.start-succeeded', $locked, $before, $locked->only('status', 'lifecycle_version', 'actual_start_at'));
            MeetingStarted::dispatch($locked);

            return $locked;
        });
    }

    private function recordFailure(Meeting $meeting, string $attemptUuid, string $reason): Meeting
    {
        return DB::transaction(function () use ($meeting, $attemptUuid, $reason) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            if ($locked->status !== MeetingStatus::Starting || ! hash_equals((string) $locked->start_attempt_uuid, $attemptUuid)) {
                return $locked;
            }

            $locked->update(['last_provider_error' => $reason]);
            $this->audit->log('meeting.start-failed', $locked, [], ['status' => $locked->status, 'lifecycle_version' => $locked->lifecycle_version, 'reason' => $reason]);

            return $locked;
        });
    }

    private function recordDefinitiveFailure(Meeting $meeting, string $attemptUuid): Meeting
    {
        return DB::transaction(function () use ($meeting, $attemptUuid) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            if ($locked->status !== MeetingStatus::Starting || ! hash_equals((string) $locked->start_attempt_uuid, $attemptUuid)) {
                return $locked;
            }
            $before = $locked->only('status', 'lifecycle_version');
            $locked->update([
                'status' => MeetingStatus::Scheduled,
                'lifecycle_version' => $locked->lifecycle_version + 1,
                'last_provider_error' => 'Meeting provider definitively rejected room creation.',
            ]);
            $this->audit->log('meeting.start-failed', $locked, $before, $locked->only('status', 'lifecycle_version', 'last_provider_error'));

            return $locked;
        });
    }
}
