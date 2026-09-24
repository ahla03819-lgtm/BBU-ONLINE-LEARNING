<?php

namespace Tests\Feature\PhaseC;

use App\Enums\MeetingSeriesStatus;
use App\Enums\MeetingStatus;
use App\Models\MeetingSeries;
use App\Services\Meetings\MeetingOccurrenceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplenishMeetingSeriesOccurrencesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_command_extends_old_open_series_idempotently_and_preserves_exceptions(): void
    {
        CarbonImmutable::setTestNow('2026-09-24 00:00:00 UTC');
        config()->set('calendar.generation_horizon_days', 5);
        config()->set('calendar.max_occurrences', 20);
        $series = MeetingSeries::factory()->create([
            'recurrence_type' => 'daily', 'starts_on' => '2024-01-01', 'ends_on' => null,
            'timezone' => 'Asia/Phnom_Penh', 'local_start_time' => '09:00:00',
        ]);
        $today = CarbonImmutable::now('Asia/Phnom_Penh')->startOfDay();
        app(MeetingOccurrenceGenerator::class)->generateThroughLocked($series, $today->addDays(2), $today);
        $meetings = $series->meetings()->orderBy('series_occurrence_on')->get();
        $cancelled = $meetings[1];
        $overridden = $meetings[2];
        $cancelled->update(['status' => MeetingStatus::Cancelled, 'series_override_at' => now()]);
        $overridden->update(['title' => 'Personal exception', 'series_override_at' => now()]);

        $this->artisan('meetings:replenish-series', ['--chunk' => 1])->assertSuccessful();
        $this->artisan('meetings:replenish-series', ['--chunk' => 2])->assertSuccessful();

        $this->assertSame(6, $series->meetings()->count());
        $this->assertSame(6, $series->meetings()->distinct()->count('series_occurrence_on'));
        $this->assertSame(MeetingStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame('Personal exception', $overridden->fresh()->title);
        $last = $series->meetings()->orderByDesc('series_occurrence_on')->first();
        $this->assertSame($today->addDays(5)->toDateString(), $last->series_occurrence_on->toDateString());
        $this->assertSame('02:00', $last->scheduled_start_at->utc()->format('H:i'));
    }

    public function test_command_ignores_cancelled_and_finite_series(): void
    {
        CarbonImmutable::setTestNow('2026-09-24 00:00:00 UTC');
        $cancelled = MeetingSeries::factory()->create(['ends_on' => null, 'status' => MeetingSeriesStatus::Cancelled]);
        $finite = MeetingSeries::factory()->create(['starts_on' => '2026-09-24', 'ends_on' => '2026-09-30']);

        $this->artisan('meetings:replenish-series')->assertSuccessful();

        $this->assertSame(0, $cancelled->meetings()->count());
        $this->assertSame(0, $finite->meetings()->count());
    }
}
