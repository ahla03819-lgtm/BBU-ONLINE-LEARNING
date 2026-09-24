<?php

namespace App\Services\Meetings;

use App\Enums\MeetingRecurrenceType;
use App\Models\MeetingSeries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RecurrenceExpander
{
    /** @return Collection<int, CarbonImmutable> */
    public function expand(MeetingSeries|array $definition, ?CarbonImmutable $through = null, ?CarbonImmutable $from = null): Collection
    {
        $type = $definition instanceof MeetingSeries ? $definition->recurrence_type : MeetingRecurrenceType::from($definition['recurrence_type']);
        $timezone = $definition instanceof MeetingSeries ? $definition->timezone : $definition['timezone'];
        $startValue = $definition instanceof MeetingSeries ? $definition->starts_on : $definition['starts_on'];
        $endValue = $definition instanceof MeetingSeries ? $definition->ends_on : $definition['ends_on'];
        $weekdays = $definition instanceof MeetingSeries ? ($definition->weekdays ?? []) : ($definition['weekdays'] ?? []);
        $definitionStart = CarbonImmutable::parse($startValue, $timezone)->startOfDay();
        $start = $from ? $from->setTimezone($timezone)->startOfDay()->max($definitionStart) : $definitionStart;
        $end = $endValue
            ? CarbonImmutable::parse($endValue, $timezone)->startOfDay()
            : ($through ? $through->setTimezone($timezone)->startOfDay() : $definitionStart->addDays((int) config('calendar.generation_horizon_days')));
        $dates = collect();

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $matches = match ($type) {
                MeetingRecurrenceType::Daily => true,
                MeetingRecurrenceType::Weekly => $date->isoWeekday() === $definitionStart->isoWeekday(),
                MeetingRecurrenceType::SelectedWeekdays => in_array($date->isoWeekday(), $weekdays, true),
            };

            if ($matches) {
                $dates->push($date);
                if ($dates->count() > config('calendar.max_occurrences')) {
                    throw ValidationException::withMessages([
                        'ends_on' => 'The recurrence exceeds the maximum of '.config('calendar.max_occurrences').' occurrences.',
                    ]);
                }
            }
        }

        if ($dates->isEmpty()) {
            throw ValidationException::withMessages(['weekdays' => 'The recurrence must produce at least one occurrence.']);
        }

        return $dates;
    }
}
