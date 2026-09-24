<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingSeriesStatus;
use App\Models\MeetingSeries;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meetings\MeetingOccurrenceGenerator;
use App\Services\Meetings\MeetingSeriesDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateMeetingSeries
{
    public function __construct(
        private MeetingSeriesDefinition $definition,
        private MeetingOccurrenceGenerator $generator,
        private AuditLogger $audit,
    ) {}

    public function handle(User $actor, MeetingSeries $series, array $data): MeetingSeries
    {
        Gate::forUser($actor)->authorize('update', $series);

        return DB::transaction(function () use ($actor, $series, $data) {
            $locked = MeetingSeries::query()->lockForUpdate()->findOrFail($series->id);
            $this->assertVersion($locked, $data);
            if ($locked->status !== MeetingSeriesStatus::Active) {
                throw ValidationException::withMessages(['series' => 'Only active meeting series may be updated.']);
            }

            $before = $locked->toArray();
            $attributes = $this->definition->attributes($actor, $locked->schoolClass, $data);
            unset($attributes['created_by'], $attributes['status']);
            $locked->fill([...$attributes, 'lifecycle_version' => $locked->lifecycle_version + 1])->save();
            $this->generator->synchronizeLocked($locked);
            $this->audit->log('meeting-series.updated', $locked, $before, $locked->toArray());

            return $locked->loadCount('meetings');
        });
    }

    private function assertVersion(MeetingSeries $series, array $data): void
    {
        if ($series->lifecycle_version !== (int) $data['lifecycle_version']) {
            throw ValidationException::withMessages(['lifecycle_version' => 'The meeting series changed. Refresh and try again.']);
        }
    }
}
