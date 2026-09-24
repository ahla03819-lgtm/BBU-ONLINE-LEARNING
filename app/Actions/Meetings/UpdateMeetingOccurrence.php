<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingStatus;
use App\Events\MeetingUpdated;
use App\Models\Meeting;
use App\Models\MeetingSeries;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateMeetingOccurrence
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, MeetingSeries $series, Meeting $meeting, array $data): Meeting
    {
        Gate::forUser($actor)->authorize('update', $series);
        Gate::forUser($actor)->authorize('update', $meeting);

        return DB::transaction(function () use ($series, $meeting, $data) {
            MeetingSeries::query()->lockForUpdate()->findOrFail($series->id);
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            if ($locked->meeting_series_id !== $series->id) {
                abort(404);
            }
            if ($locked->status !== MeetingStatus::Scheduled || $locked->scheduled_start_at->lte(now())) {
                throw ValidationException::withMessages(['meeting' => 'Only future scheduled occurrences may be edited.']);
            }
            if ($locked->lifecycle_version !== (int) $data['lifecycle_version']) {
                throw ValidationException::withMessages(['lifecycle_version' => 'The meeting occurrence changed. Refresh and try again.']);
            }

            $before = $locked->only('title', 'description', 'scheduled_start_at', 'scheduled_end_at', 'max_participants', 'lifecycle_version');
            $locked->update([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'scheduled_start_at' => $data['scheduled_start_at'],
                'scheduled_end_at' => $data['scheduled_end_at'],
                'max_participants' => $data['max_participants'],
                'series_override_at' => now(),
                'lifecycle_version' => $locked->lifecycle_version + 1,
            ]);
            $this->audit->log('meeting-series.occurrence-updated', $locked, $before, $locked->only(
                'title', 'description', 'scheduled_start_at', 'scheduled_end_at', 'max_participants', 'lifecycle_version'
            ));
            MeetingUpdated::dispatch($locked);

            return $locked;
        });
    }
}
