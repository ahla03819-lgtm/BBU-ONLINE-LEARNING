<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingSeriesStatus;
use App\Models\MeetingSeries;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meetings\MeetingOccurrenceGenerator;
use App\Services\Meetings\MeetingSeriesDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SplitMeetingSeries
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
            $source = MeetingSeries::query()->lockForUpdate()->findOrFail($series->id);
            if ($source->lifecycle_version !== (int) $data['lifecycle_version']) {
                throw ValidationException::withMessages(['lifecycle_version' => 'The meeting series changed. Refresh and try again.']);
            }
            if ($source->status !== MeetingSeriesStatus::Active || $data['starts_on'] !== $data['cutoff_on']) {
                throw ValidationException::withMessages(['cutoff_on' => 'The split must start on the selected future occurrence date.']);
            }

            $cutoff = CarbonImmutable::parse($data['cutoff_on'], $source->timezone);
            if ($cutoff->lte($source->starts_on) || ($source->ends_on && $cutoff->gt($source->ends_on))) {
                throw ValidationException::withMessages(['cutoff_on' => 'Choose a future date inside the current series.']);
            }

            $target = MeetingSeries::query()->create([
                ...$this->definition->attributes($actor, $source->schoolClass, $data),
                'lifecycle_version' => 0,
                'split_from_series_id' => $source->id,
            ]);

            $sourceBefore = $source->toArray();
            $source->update([
                'ends_on' => $cutoff->subDay()->toDateString(),
                'lifecycle_version' => $source->lifecycle_version + 1,
            ]);
            $this->generator->reconcileSplitLocked($source, $target, $data['cutoff_on']);
            $this->audit->log('meeting-series.split', $source, $sourceBefore, [
                'ends_on' => $source->ends_on,
                'lifecycle_version' => $source->lifecycle_version,
                'split_series_id' => $target->id,
            ]);

            return $target->loadCount('meetings');
        });
    }
}
