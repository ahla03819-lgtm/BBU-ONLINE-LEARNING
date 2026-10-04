<?php

namespace Tests\Feature;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Meeting schedule timezone contract.
 *
 * The browser sends offset-less datetime-local values ("YYYY-MM-DDTHH:mm")
 * representing wall-clock time in the academic calendar timezone. The server
 * must persist UTC. These tests pin both directions of that contract through
 * the real HTTP create and update paths.
 */
class MeetingScheduleTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function activeClass(): \App\Models\SchoolClass
    {
        return \App\Models\SchoolClass::factory()->create([
            'academic_year_id' => \App\Models\AcademicYear::factory()->active(),
            'status' => \App\Enums\SchoolClassStatus::Active,
        ]);
    }

    private function classTeacher(\App\Models\SchoolClass $class): \App\Models\User
    {
        $teacher = tap(\App\Models\User::factory()->create(), fn ($u) => $u->assignRole('Teacher'));
        $profile = \App\Models\TeacherProfile::factory()->create(['user_id' => $teacher->id]);
        \App\Models\TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'school_class_id' => $class->id,
            'current_slot' => 1,
            'ends_on' => null,
        ]);

        return $teacher;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Timezone contract lesson',
            'scheduled_start_at' => '2026-10-10T13:00',
            'scheduled_end_at' => '2026-10-10T14:30',
        ], $overrides);
    }

    private function assertPersistedUtc(Meeting $meeting, string $startUtc, string $endUtc, string $message = ''): void
    {
        $this->assertSame(
            $startUtc,
            $meeting->scheduled_start_at->utc()->format('Y-m-d H:i:s'),
            $message.' expected start '.$startUtc
        );
        $this->assertSame(
            $endUtc,
            $meeting->scheduled_end_at->utc()->format('Y-m-d H:i:s'),
            $message.' expected end '.$endUtc
        );
    }

    /**
     * 13:00 Asia/Phnom_Penh (+07:00) === 06:00 UTC.
     */
    public function test_create_persists_academic_local_schedule_as_utc(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload())->assertRedirect();

        $this->assertPersistedUtc(
            Meeting::query()->sole(),
            '2026-10-10 06:00:00',
            '2026-10-10 07:30:00',
            'Offset-less datetime-local values are academic-local wall clock.'
        );
    }

    /**
     * The rendered datetime-local value for a stored UTC instant must be the
     * academic-local wall clock, so an edit round trip is stable.
     */
    public function test_update_without_changing_the_time_preserves_the_same_instant(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload())->assertRedirect();
        $meeting = Meeting::query()->sole();
        $this->assertPersistedUtc($meeting, '2026-10-10 06:00:00', '2026-10-10 07:30:00');

        // Exactly what MeetingForm resubmits: the stored instant rendered into a
        // datetime-local input in the academic timezone.
        $start = \Illuminate\Support\Carbon::parse($meeting->scheduled_start_at)
            ->setTimezone(config('calendar.default_timezone'))->format('Y-m-d\TH:i');
        $end = \Illuminate\Support\Carbon::parse($meeting->scheduled_end_at)
            ->setTimezone(config('calendar.default_timezone'))->format('Y-m-d\TH:i');

        $this->assertSame('2026-10-10T13:00', $start, 'prefill must show academic-local wall clock');
        $this->assertSame('2026-10-10T14:30', $end);

        $this->actingAs($teacher)->patch(route('meetings.update', [$class, $meeting]), [
            'title' => $meeting->title,
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $end,
        ])->assertRedirect();

        $this->assertPersistedUtc(
            $meeting->fresh(),
            '2026-10-10 06:00:00',
            '2026-10-10 07:30:00',
            'An unchanged resubmission must not drift.'
        );
    }

    public function test_update_to_a_new_local_time_persists_the_new_utc_instant(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload())->assertRedirect();
        $meeting = Meeting::query()->sole();

        $this->actingAs($teacher)->patch(route('meetings.update', [$class, $meeting]), [
            'title' => $meeting->title,
            'scheduled_start_at' => '2026-10-10T15:00',
            'scheduled_end_at' => '2026-10-10T16:30',
            'max_participants' => $meeting->max_participants,
        ])->assertRedirect();

        $this->assertPersistedUtc(
            $meeting->fresh(),
            '2026-10-10 08:00:00',
            '2026-10-10 09:30:00',
            'A reschedule must land at the requested local time.'
        );
    }

    public function test_end_before_start_is_rejected(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload([
            'scheduled_start_at' => '2026-10-10T13:00',
            'scheduled_end_at' => '2026-10-10T12:00',
        ]))->assertSessionHasErrors('scheduled_end_at');

        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_end_equal_to_start_is_rejected(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload([
            'scheduled_start_at' => '2026-10-10T13:00',
            'scheduled_end_at' => '2026-10-10T13:00',
        ]))->assertSessionHasErrors('scheduled_end_at');

        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_a_value_that_already_carries_an_offset_is_preserved(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload([
            'scheduled_start_at' => '2026-10-10T06:00:00Z',
            'scheduled_end_at' => '2026-10-10T07:30:00Z',
        ]))->assertRedirect();

        $this->assertPersistedUtc(
            Meeting::query()->sole(),
            '2026-10-10 06:00:00',
            '2026-10-10 07:30:00',
            'An explicit UTC instant must be stored as sent.'
        );
    }

    public function test_legacy_space_separated_utc_payload_still_persists_as_utc(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload([
            'scheduled_start_at' => '2026-10-10 06:00:00',
            'scheduled_end_at' => '2026-10-10 07:30:00',
        ]))->assertRedirect();

        $this->assertPersistedUtc(Meeting::query()->sole(), '2026-10-10 06:00:00', '2026-10-10 07:30:00');
    }

    public function test_stored_meeting_exposes_the_calendar_range_it_was_scheduled_into(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload())->assertRedirect();
        $meeting = Meeting::query()->sole();
        $this->assertSame(MeetingStatus::Scheduled, $meeting->status);

        // The meeting must fall inside the local day the user scheduled it on.
        $local = \Illuminate\Support\Carbon::parse($meeting->scheduled_start_at)
            ->setTimezone(config('calendar.default_timezone'));
        $this->assertSame('2026-10-10 13:00', $local->format('Y-m-d H:i'));
    }
}
