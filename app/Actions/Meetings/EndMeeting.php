<?php

namespace App\Actions\Meetings;

use App\Contracts\MeetingLifecycleProvider;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Enums\MeetingStatus;
use App\Events\MeetingEnded;
use App\Events\MeetingEnding;
use App\Events\MeetingScreenShareRequestChanged;
use App\Models\Meeting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class EndMeeting
{
    public function __construct(private MeetingLifecycleProvider $provider, private AuditLogger $audit) {}

    public function handle(User $actor, Meeting $meeting): Meeting
    {
        Gate::forUser($actor)->authorize('end', $meeting);

        [$authoritative, $endingVersion, $invokeProvider] = DB::transaction(function () use ($actor, $meeting) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            Gate::forUser($actor)->authorize('end', $locked);
            if ($locked->status === MeetingStatus::Ended || $locked->status === MeetingStatus::Ending) {
                return [$locked, $locked->lifecycle_version, false];
            }
            if ($locked->status !== MeetingStatus::Active) {
                throw ValidationException::withMessages(['meeting' => 'This meeting cannot be ended from its current state.']);
            }

            $before = $locked->only('status', 'lifecycle_version');
            $locked->update([
                'status' => MeetingStatus::Ending,
                'lifecycle_version' => $locked->lifecycle_version + 1,
                'last_provider_error' => null,
            ]);
            $locked->screenShareRequests()->whereNotNull('active_slot')->get()->each(function ($request) {
                $request->update(['status' => MeetingScreenShareRequestStatus::Expired, 'active_slot' => null, 'completed_at' => now()]);
                MeetingScreenShareRequestChanged::dispatch($request);
            });
            $this->audit->log('meeting.end-requested', $locked, $before, $locked->only('status', 'lifecycle_version'));
            MeetingEnding::dispatch($locked);

            return [$locked, $locked->lifecycle_version, true];
        });

        if (! $invokeProvider) {
            return $authoritative;
        }

        try {
            $state = $this->provider->end($authoritative);
        } catch (Throwable) {
            return $this->recordFailure($authoritative, $endingVersion, 'Meeting provider end failed.');
        }

        if ($state !== MeetingProviderState::Ended) {
            return $authoritative->fresh();
        }

        return $this->complete($authoritative, $endingVersion);
    }

    public function complete(Meeting $meeting, int $endingVersion): Meeting
    {
        return DB::transaction(function () use ($meeting, $endingVersion) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            if ($locked->status === MeetingStatus::Ended) {
                return $locked;
            }
            if ($locked->status !== MeetingStatus::Ending || $locked->lifecycle_version !== $endingVersion) {
                return $locked;
            }

            $before = $locked->only('status', 'lifecycle_version');
            $locked->update([
                'status' => MeetingStatus::Ended,
                'actual_end_at' => $locked->actual_end_at ?? now(),
                'lifecycle_version' => $locked->lifecycle_version + 1,
                'last_provider_error' => null,
            ]);
            $this->audit->log('meeting.end-succeeded', $locked, $before, $locked->only('status', 'lifecycle_version', 'actual_end_at'));
            MeetingEnded::dispatch($locked);

            return $locked;
        });
    }

    private function recordFailure(Meeting $meeting, int $endingVersion, string $reason): Meeting
    {
        return DB::transaction(function () use ($meeting, $endingVersion, $reason) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            if ($locked->status !== MeetingStatus::Ending || $locked->lifecycle_version !== $endingVersion) {
                return $locked;
            }

            $locked->update(['last_provider_error' => $reason]);
            $this->audit->log('meeting.end-failed', $locked, [], ['status' => $locked->status, 'lifecycle_version' => $locked->lifecycle_version, 'reason' => $reason]);

            return $locked;
        });
    }
}
