<?php

namespace Tests\Unit\PhaseC;

use App\Models\MeetingSeries;
use App\Services\Meetings\MeetingOccurrenceGenerator;
use App\Services\Meetings\RecurrenceExpander;
use Tests\TestCase;

class RecurrenceExpanderTest extends TestCase
{
    public function test_daily_weekly_and_selected_weekday_rules_expand_the_expected_local_dates(): void
    {
        $expander = app(RecurrenceExpander::class);
        $base = ['starts_on' => '2026-10-05', 'ends_on' => '2026-10-11', 'timezone' => 'Asia/Phnom_Penh'];

        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'], $expander->expand([...$base, 'recurrence_type' => 'daily'])->map->toDateString()->all());
        $this->assertSame(['2026-10-05'], $expander->expand([...$base, 'recurrence_type' => 'weekly'])->map->toDateString()->all());
        $this->assertSame(['2026-10-05', '2026-10-07', '2026-10-09'], $expander->expand([...$base, 'recurrence_type' => 'selected_weekdays', 'weekdays' => [1, 3, 5]])->map->toDateString()->all());
    }

    public function test_open_ended_recurrence_uses_the_bounded_generation_horizon(): void
    {
        config()->set('calendar.generation_horizon_days', 2);

        $dates = app(RecurrenceExpander::class)->expand([
            'recurrence_type' => 'daily', 'starts_on' => '2026-10-05', 'ends_on' => null, 'timezone' => 'Asia/Phnom_Penh',
        ]);

        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07'], $dates->map->toDateString()->all());
    }

    public function test_local_wall_clock_schedule_is_persisted_as_utc(): void
    {
        $series = new MeetingSeries([
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'local_start_time' => '09:30:00', 'duration_minutes' => 60, 'timezone' => 'Asia/Phnom_Penh',
        ]);
        $date = app(RecurrenceExpander::class)->expand(['recurrence_type' => 'daily', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'timezone' => 'Asia/Phnom_Penh'])->first();

        [$start, $end] = app(MeetingOccurrenceGenerator::class)->schedule($series, $date);

        $this->assertSame('2026-10-05 02:30:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 03:30:00', $end->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $start->getTimezone()->getName());
    }
}
