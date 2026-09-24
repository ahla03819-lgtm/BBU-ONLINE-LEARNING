<?php

namespace Tests\Feature\PhaseC;

use App\Enums\MeetingStatus;
use App\Models\MeetingSeries;
use App\Services\Meetings\MeetingOccurrenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingOccurrenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_generation_does_not_duplicate_series_occurrences(): void
    {
        $series = MeetingSeries::factory()->create(['recurrence_type' => 'daily', 'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDays(3)->toDateString()]);
        $generator = app(MeetingOccurrenceGenerator::class);
        $generator->generate($series);
        $generator->generate($series);

        $this->assertSame(3, $series->meetings()->count());
        $this->assertSame(3, $series->meetings()->distinct()->count('series_occurrence_on'));
    }

    public function test_cancelled_and_overridden_occurrences_are_not_recreated_or_overwritten(): void
    {
        $series = MeetingSeries::factory()->create(['recurrence_type' => 'daily', 'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDays(3)->toDateString()]);
        $generator = app(MeetingOccurrenceGenerator::class);
        $generator->generate($series);
        $meetings = $series->meetings()->orderBy('series_occurrence_on')->get();
        $cancelled = $meetings->first();
        $overridden = $meetings->last();
        $cancelled->update(['status' => MeetingStatus::Cancelled, 'series_override_at' => now()]);
        $overridden->update(['title' => 'Exception title', 'series_override_at' => now()]);
        $series->update(['title' => 'Changed series title', 'lifecycle_version' => 1]);

        $generator->generate($series);
        $generator->synchronizeLocked($series);

        $this->assertSame(3, $series->meetings()->count());
        $this->assertSame(MeetingStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame('Exception title', $overridden->fresh()->title);
    }
}
