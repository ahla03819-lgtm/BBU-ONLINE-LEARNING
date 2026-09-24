<?php

namespace Tests\Feature\PhaseC;

use App\Actions\Meetings\CancelMeetingOccurrence;
use App\Actions\Meetings\CancelMeetingSeries;
use App\Actions\Meetings\SplitMeetingSeries;
use App\Actions\Meetings\UpdateMeetingOccurrence;
use App\Actions\Meetings\UpdateMeetingSeries;
use App\Enums\MeetingSeriesStatus;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\MeetingSeries;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Meetings\MeetingOccurrenceGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingSeriesActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-24 00:00:00 UTC');
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_whole_series_update_changes_only_future_untouched_occurrences_without_duplicates(): void
    {
        [$class, $teacher] = $this->context();
        $series = $this->series($class, $teacher, ['starts_on' => '2026-09-22', 'ends_on' => '2026-09-28']);
        app(MeetingOccurrenceGenerator::class)->generate($series);
        $byDate = $series->meetings->keyBy(fn ($meeting) => $meeting->series_occurrence_on->toDateString());
        $past = $byDate['2026-09-22'];
        $ended = $byDate['2026-09-23'];
        $cancelled = $byDate['2026-09-27'];
        $overridden = $byDate['2026-09-28'];
        $ended->update(['status' => MeetingStatus::Ended]);
        $cancelled->update(['status' => MeetingStatus::Cancelled, 'series_override_at' => now()]);
        $overridden->update(['title' => 'Personal title', 'series_override_at' => now()]);

        app(UpdateMeetingSeries::class)->handle($teacher, $series, $this->definition(['title' => 'Updated series', 'local_start_time' => '10:00', 'lifecycle_version' => 0]));
        app(MeetingOccurrenceGenerator::class)->synchronizeLocked($series->fresh());

        $this->assertNotSame('Updated series', $past->fresh()->title);
        $this->assertSame(MeetingStatus::Ended, $ended->fresh()->status);
        $this->assertSame(MeetingStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame('Personal title', $overridden->fresh()->title);
        $this->assertSame('Updated series', $byDate['2026-09-25']->fresh()->title);
        $this->assertSame(7, $series->meetings()->count());
    }

    public function test_single_occurrence_edit_and_cancel_survive_later_synchronization(): void
    {
        [$class, $teacher] = $this->context();
        $series = $this->series($class, $teacher, ['starts_on' => '2026-09-25', 'ends_on' => '2026-09-27']);
        app(MeetingOccurrenceGenerator::class)->generate($series);
        $meetings = $series->meetings()->orderBy('series_occurrence_on')->get();
        $edited = $meetings[1];
        $cancelled = $meetings[0];
        $untouched = $meetings[2];
        $originalSeriesTitle = $series->title;

        app(UpdateMeetingOccurrence::class)->handle($teacher, $series, $edited, [
            'title' => 'One-off lesson', 'description' => null,
            'scheduled_start_at' => '2026-09-26 05:00:00', 'scheduled_end_at' => '2026-09-26 06:00:00',
            'max_participants' => 40, 'lifecycle_version' => 0,
        ]);
        app(CancelMeetingOccurrence::class)->handle($teacher, $series, $cancelled, 0);
        app(MeetingOccurrenceGenerator::class)->synchronizeLocked($series->fresh());

        $this->assertSame('One-off lesson', $edited->fresh()->title);
        $this->assertNotNull($edited->fresh()->series_override_at);
        $this->assertSame(MeetingStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertNotNull($cancelled->fresh()->series_override_at);
        $this->assertSame($originalSeriesTitle, $series->fresh()->title);
        $this->assertSame($originalSeriesTitle, $untouched->fresh()->title);
        $this->assertSame(3, $series->meetings()->count());
    }

    public function test_series_cancellation_preserves_history_stops_future_generation_and_is_safe_twice(): void
    {
        [$class, $teacher] = $this->context();
        $series = $this->series($class, $teacher, ['starts_on' => '2026-09-23', 'ends_on' => '2026-09-27']);
        app(MeetingOccurrenceGenerator::class)->generate($series);
        $past = $series->meetings()->orderBy('series_occurrence_on')->first();

        $first = app(CancelMeetingSeries::class)->handle($teacher, $series, ['scope' => 'entire', 'cutoff_on' => null, 'lifecycle_version' => 0]);
        $second = app(CancelMeetingSeries::class)->handle($teacher, $first, ['scope' => 'entire', 'cutoff_on' => null, 'lifecycle_version' => 1]);

        $this->assertSame(MeetingSeriesStatus::Cancelled, $second->status);
        $this->assertSame(MeetingStatus::Scheduled, $past->fresh()->status);
        $this->assertSame(5, $series->meetings()->count());
        $this->assertSame(4, $series->meetings()->where('status', MeetingStatus::Cancelled->value)->count());
    }

    public function test_split_transfers_future_normal_cancelled_and_overridden_identities_without_duplicates(): void
    {
        [$class, $teacher] = $this->context();
        $source = $this->series($class, $teacher, ['starts_on' => '2026-09-23', 'ends_on' => '2026-09-28']);
        app(MeetingOccurrenceGenerator::class)->generate($source);
        $byDate = $source->meetings->keyBy(fn ($meeting) => $meeting->series_occurrence_on->toDateString());
        $byDate['2026-09-25']->update(['status' => MeetingStatus::Cancelled, 'series_override_at' => now()]);
        $byDate['2026-09-26']->update(['title' => 'Kept exception', 'series_override_at' => now()]);

        $target = app(SplitMeetingSeries::class)->handle($teacher, $source, $this->definition([
            'starts_on' => '2026-09-25', 'ends_on' => '2026-09-28', 'cutoff_on' => '2026-09-25', 'lifecycle_version' => 0,
        ]));

        $this->assertSame('2026-09-24', $source->fresh()->ends_on->toDateString());
        $this->assertSame(2, $source->meetings()->count());
        $this->assertSame(4, $target->meetings()->count());
        $this->assertSame(MeetingStatus::Cancelled, $byDate['2026-09-25']->fresh()->status);
        $this->assertSame($target->id, $byDate['2026-09-25']->fresh()->meeting_series_id);
        $this->assertSame('Kept exception', $byDate['2026-09-26']->fresh()->title);
        $this->assertSame(4, $target->meetings()->distinct()->count('series_occurrence_on'));
    }

    public function test_old_open_ended_series_can_split_at_a_current_occurrence_and_target_stays_open(): void
    {
        [$class, $teacher] = $this->context();
        $source = $this->series($class, $teacher, ['starts_on' => '2024-01-01', 'ends_on' => null]);

        $target = app(SplitMeetingSeries::class)->handle($teacher, $source, $this->definition([
            'starts_on' => '2026-09-25', 'ends_on' => null, 'cutoff_on' => '2026-09-25', 'lifecycle_version' => 0,
        ]));

        $this->assertSame('2026-09-24', $source->fresh()->ends_on->toDateString());
        $this->assertNull($target->ends_on);
        $this->assertSame(366, $target->meetings()->count());
    }

    private function context(): array
    {
        $year = AcademicYear::factory()->active()->create(['starts_on' => '2023-01-01', 'ends_on' => '2028-12-31']);
        $class = SchoolClass::factory()->create(['academic_year_id' => $year->id, 'status' => SchoolClassStatus::Active]);
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $teacher->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id]);

        return [$class, $teacher];
    }

    private function series(SchoolClass $class, User $teacher, array $overrides = []): MeetingSeries
    {
        return MeetingSeries::factory()->create(array_merge([
            'school_class_id' => $class->id, 'created_by' => $teacher->id, 'host_user_id' => $teacher->id,
            'title' => 'Original series', 'recurrence_type' => 'daily', 'weekdays' => null,
            'local_start_time' => '09:00:00', 'timezone' => 'Asia/Phnom_Penh', 'duration_minutes' => 60, 'max_participants' => 50,
        ], $overrides));
    }

    private function definition(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Updated series', 'description' => null, 'class_subject_id' => null, 'host_user_id' => null,
            'recurrence_type' => 'daily', 'weekdays' => null, 'starts_on' => '2026-09-22', 'ends_on' => '2026-09-28',
            'local_start_time' => '09:00', 'duration_minutes' => 60, 'timezone' => 'Asia/Phnom_Penh', 'max_participants' => 50,
        ], $overrides);
    }
}
