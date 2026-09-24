<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingSeriesStatus;
use App\Enums\MeetingStatus;
use App\Models\MeetingSeries;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelMeetingSeries
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, MeetingSeries $series, array $data): MeetingSeries
    {
        Gate::forUser($actor)->authorize('cancel', $series);

        return DB::transaction(function () use ($series, $data) {
            $locked = MeetingSeries::query()->lockForUpdate()->findOrFail($series->id);
            if ($locked->lifecycle_version !== (int) $data['lifecycle_version']) {
                throw ValidationException::withMessages(['lifecycle_version' => 'The meeting series changed. Refresh and try again.']);
            }

            $cutoff = $data['scope'] === 'entire'
                ? $locked->starts_on->toDateString()
                : $data['cutoff_on'];
            $before = $locked->toArray();
            $newVersion = $locked->lifecycle_version + 1;

            $occurrences = $locked->meetings()
                ->where('series_occurrence_on', '>=', $cutoff)
                ->where('status', MeetingStatus::Scheduled->value)
                ->where('scheduled_start_at', '>', now())
                ->lockForUpdate()
                ->get();

            foreach ($occurrences as $meeting) {
                $meeting->update([
                    'status' => MeetingStatus::Cancelled,
                    'lifecycle_version' => $meeting->lifecycle_version + 1,
                    'series_sync_version' => $newVersion,
                ]);
            }

            $updates = ['lifecycle_version' => $newVersion];
            if ($data['scope'] === 'entire' || $cutoff <= $locked->starts_on->toDateString()) {
                $updates['status'] = MeetingSeriesStatus::Cancelled;
            } else {
                $updates['ends_on'] = CarbonImmutable::parse($cutoff, $locked->timezone)->subDay()->toDateString();
            }
            $locked->update($updates);
            $this->audit->log('meeting-series.cancelled', $locked, $before, [
                ...$locked->only('status', 'ends_on', 'lifecycle_version'),
                'scope' => $data['scope'],
                'cutoff_on' => $cutoff,
                'cancelled_occurrences' => $occurrences->count(),
            ]);

            return $locked->loadCount('meetings');
        });
    }
}
