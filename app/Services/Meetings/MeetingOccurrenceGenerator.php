<?php

namespace App\Services\Meetings;

use App\Enums\MeetingJoinPolicy;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingSeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class MeetingOccurrenceGenerator
{
    public function __construct(private RecurrenceExpander $expander) {}

    public function generate(MeetingSeries $series): EloquentCollection
    {
        return DB::transaction(function () use ($series) {
            $locked = MeetingSeries::query()->lockForUpdate()->findOrFail($series->id);
            $this->generateLocked($locked);

            return $locked->meetings()->orderBy('series_occurrence_on')->get();
        });
    }

    public function generateLocked(MeetingSeries $series): void
    {
        $this->generateThroughLocked($series);
    }

    public function generateThroughLocked(MeetingSeries $series, ?CarbonImmutable $through = null, ?CarbonImmutable $from = null): void
    {
        $existing = $series->meetings()->lockForUpdate()->get()->keyBy(
            fn (Meeting $meeting) => $meeting->series_occurrence_on?->toDateString()
        );

        foreach ($this->expander->expand($series, $through, $from) as $date) {
            $key = $date->toDateString();
            if ($existing->has($key)) {
                continue;
            }

            [$start, $end] = $this->schedule($series, $date);
            $series->meetings()->create([
                ...$this->meetingAttributes($series),
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
                'status' => MeetingStatus::Scheduled,
                'join_policy' => MeetingJoinPolicy::ActiveOnly,
                'lifecycle_version' => 0,
                'series_occurrence_on' => $key,
                'series_sync_version' => $series->lifecycle_version,
            ]);
        }
    }

    public function synchronizeLocked(MeetingSeries $series): void
    {
        $desired = $this->expander->expand($series)->keyBy(fn (CarbonImmutable $date) => $date->toDateString());
        $meetings = $series->meetings()
            ->where('status', MeetingStatus::Scheduled->value)
            ->whereNull('series_override_at')
            ->where('scheduled_start_at', '>', now())
            ->lockForUpdate()
            ->get();

        foreach ($meetings as $meeting) {
            $key = $meeting->series_occurrence_on->toDateString();
            if (! $desired->has($key)) {
                $this->cancelForSeriesMutation($meeting, $series->lifecycle_version);

                continue;
            }

            $this->synchronizeMeeting($meeting, $series, $desired->get($key));
        }

        $this->generateLocked($series);
    }

    public function reconcileSplitLocked(MeetingSeries $source, MeetingSeries $target, string $cutoff): void
    {
        $desired = $this->expander->expand($target)->keyBy(fn (CarbonImmutable $date) => $date->toDateString());
        $sourceMeetings = $source->meetings()
            ->where('series_occurrence_on', '>=', $cutoff)
            ->lockForUpdate()
            ->get();

        foreach ($sourceMeetings as $meeting) {
            $key = $meeting->series_occurrence_on->toDateString();
            if (! $desired->has($key)) {
                if ($meeting->status === MeetingStatus::Scheduled && ! $meeting->series_override_at && $meeting->scheduled_start_at->isFuture()) {
                    $this->cancelForSeriesMutation($meeting, $source->lifecycle_version);
                }

                continue;
            }

            $meeting->meeting_series_id = $target->id;
            if ($meeting->status === MeetingStatus::Scheduled && ! $meeting->series_override_at && $meeting->scheduled_start_at->isFuture()) {
                $this->synchronizeMeeting($meeting, $target, $desired->get($key));
            } else {
                $meeting->series_sync_version = $target->lifecycle_version;
                $meeting->save();
            }
        }

        $this->generateLocked($target);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function schedule(MeetingSeries $series, CarbonImmutable $date): array
    {
        $start = CarbonImmutable::parse(
            $date->toDateString().' '.$series->local_start_time,
            $series->timezone,
        );
        $end = $start->addMinutes($series->duration_minutes);

        return [$start->utc(), $end->utc()];
    }

    private function synchronizeMeeting(Meeting $meeting, MeetingSeries $series, CarbonImmutable $date): void
    {
        [$start, $end] = $this->schedule($series, $date);
        $meeting->fill([
            ...$this->meetingAttributes($series),
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $end,
            'series_sync_version' => $series->lifecycle_version,
        ])->save();
    }

    private function cancelForSeriesMutation(Meeting $meeting, int $syncVersion): void
    {
        $meeting->update([
            'status' => MeetingStatus::Cancelled,
            'lifecycle_version' => $meeting->lifecycle_version + 1,
            'series_sync_version' => $syncVersion,
        ]);
    }

    private function meetingAttributes(MeetingSeries $series): array
    {
        return [
            'school_class_id' => $series->school_class_id,
            'class_subject_id' => $series->class_subject_id,
            'created_by' => $series->created_by,
            'host_user_id' => $series->host_user_id,
            'title' => $series->title,
            'description' => $series->description,
            'max_participants' => $series->max_participants,
        ];
    }
}
