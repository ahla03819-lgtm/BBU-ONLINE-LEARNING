<?php

namespace App\Actions\Meetings;

use App\Contracts\MeetingLifecycleProvider;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingStatus;
use App\Events\MeetingEnded;
use App\Events\MeetingStarted;
use App\Events\MeetingUpdated;
use App\Models\Meeting;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ReconcileMeetingLifecycle
{
    public function __construct(private MeetingLifecycleProvider $provider, private AuditLogger $audit) {}

    public function handle(Meeting $meeting, bool $dryRun = false): Meeting
    {
        $authoritative = $meeting->fresh();
        if (! in_array($authoritative->status, [MeetingStatus::Starting, MeetingStatus::Ending], true)) {
            return $authoritative;
        }

        $providerState = $this->provider->inspect($authoritative);
        if ($dryRun || ! $this->canRecover($authoritative, $providerState)) {
            return $authoritative;
        }

        return DB::transaction(function () use ($authoritative, $providerState) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($authoritative->id);
            if (! $this->canRecover($locked, $providerState)) {
                return $locked;
            }

            $before = $locked->only('status', 'lifecycle_version');
            if ($locked->status === MeetingStatus::Starting) {
                $active = $providerState === MeetingProviderState::Active;
                $locked->update([
                    'status' => $active ? MeetingStatus::Active : MeetingStatus::Scheduled,
                    'actual_start_at' => $active ? ($locked->actual_start_at ?? now()) : null,
                    'lifecycle_version' => $locked->lifecycle_version + 1,
                    'last_provider_error' => $active ? null : 'Meeting room does not exist at the provider.',
                ]);
                $event = $active ? 'meeting.start-recovered' : 'meeting.start-failed';
            } else {
                $locked->update([
                    'status' => MeetingStatus::Ended,
                    'actual_end_at' => $locked->actual_end_at ?? now(),
                    'lifecycle_version' => $locked->lifecycle_version + 1,
                    'last_provider_error' => null,
                ]);
                $event = 'meeting.end-recovered';
            }
            $this->audit->log($event, $locked, $before, $locked->only('status', 'lifecycle_version', 'actual_start_at', 'actual_end_at'));
            match ($locked->status) {
                MeetingStatus::Active => MeetingStarted::dispatch($locked),
                MeetingStatus::Ended => MeetingEnded::dispatch($locked),
                default => MeetingUpdated::dispatch($locked),
            };

            return $locked;
        });
    }

    private function canRecover(Meeting $meeting, MeetingProviderState $providerState): bool
    {
        return ($meeting->status === MeetingStatus::Starting
                && $meeting->start_attempt_uuid
                && in_array($providerState, [MeetingProviderState::Active, MeetingProviderState::Ended], true))
            || ($meeting->status === MeetingStatus::Ending
                && $providerState === MeetingProviderState::Ended);
    }
}
