<?php

namespace Tests\Feature;

use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A meeting whose stored interval is inverted or partial must not be presented to
 * users as a real schedule. The canonical rule lives on the model so controllers
 * and UI cannot drift apart.
 *
 * Synthetic rows only - no historical meeting is touched.
 */
class MeetingScheduleAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private ClassSubject $subject;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->class = SchoolClass::factory()->create([
            'academic_year_id' => AcademicYear::factory()->active(),
            'status' => SchoolClassStatus::Active,
        ]);
        // Mirror the proven MeetingCrudTest pattern: the factory supplies a real
        // subject_id, so it must not be overridden here.
        $this->subject = ClassSubject::factory()->create(['school_class_id' => $this->class->id]);

        $this->teacher = tap(User::factory()->create(), fn (User $u) => $u->assignRole('Teacher'));
        $profile = TeacherProfile::factory()->create(['user_id' => $this->teacher->id]);
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'school_class_id' => $this->class->id,
            'current_slot' => 1,
            'ends_on' => null,
        ]);
    }

    private function meeting(array $overrides = []): Meeting
    {
        return Meeting::factory()->create(array_merge([
            'school_class_id' => $this->class->id,
            'class_subject_id' => $this->subject->id,
            'created_by' => $this->teacher->id,
            'host_user_id' => $this->teacher->id,
            'status' => MeetingStatus::Scheduled,
            'scheduled_start_at' => '2026-10-10 06:00:00',
            'scheduled_end_at' => '2026-10-10 07:30:00',
        ], $overrides));
    }

    // ------------------------------------------------ canonical rule

    public function test_a_coherent_interval_is_valid(): void
    {
        $this->assertTrue($this->meeting()->hasValidScheduledInterval());
    }

    public function test_an_inverted_interval_is_not_valid(): void
    {
        $meeting = $this->meeting([
            'scheduled_start_at' => '2026-10-03 03:13:13',
            'scheduled_end_at' => '2026-09-20 10:14:03',
        ]);

        $this->assertTrue($meeting->scheduled_end_at->lt($meeting->scheduled_start_at));
        $this->assertFalse($meeting->hasValidScheduledInterval());
    }

    public function test_a_zero_length_interval_is_not_valid(): void
    {
        $meeting = $this->meeting([
            'scheduled_start_at' => '2026-10-10 06:00:00',
            'scheduled_end_at' => '2026-10-10 06:00:00',
        ]);

        $this->assertFalse($meeting->hasValidScheduledInterval());
    }

    public function test_a_missing_end_is_not_valid(): void
    {
        // scheduled_end_at is nullable in the schema, so an open-ended meeting is
        // a real, reachable state.
        $this->assertFalse($this->meeting(['scheduled_end_at' => null])->hasValidScheduledInterval());
    }

    public function test_a_missing_start_is_not_valid(): void
    {
        // meetings.scheduled_start_at is NOT NULL, so this cannot be persisted.
        // Assert the rule directly on an unsaved instance instead of asserting an
        // unreachable database state.
        $unsaved = new Meeting(['scheduled_end_at' => now()]);

        $this->assertFalse($unsaved->hasValidScheduledInterval());
        $this->assertFalse((new Meeting)->hasValidScheduledInterval());
    }

    // ------------------------------------------------ server contract

    public function test_show_exposes_schedule_availability_and_actual_activity_separately(): void
    {
        $meeting = $this->meeting([
            'scheduled_start_at' => '2026-10-03 03:13:13',
            'scheduled_end_at' => '2026-09-20 10:14:03',
            'actual_start_at' => '2026-09-19 09:14:03',
        ]);

        $this->actingAs($this->teacher)
            ->get(route('meetings.show', [$this->class, $meeting]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('meeting.schedule_available', false)
                ->where('meeting.actual_start_at', '2026-09-19T09:14:03+00:00')
                ->where('meeting.scheduled_start_at', '2026-10-03T03:13:13+00:00')
                ->where('meeting.scheduled_end_at', '2026-09-20T10:14:03+00:00')
            );
    }

    public function test_a_valid_meeting_reports_an_available_schedule(): void
    {
        $meeting = $this->meeting();

        $this->actingAs($this->teacher)
            ->get(route('meetings.show', [$this->class, $meeting]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('meeting.schedule_available', true));
    }

    /**
     * The calendar must not fabricate an event for an invalid interval, and the
     * BUG-06 behaviour (ended meetings remain visible) must be unaffected.
     */
    public function test_calendar_excludes_an_invalid_interval_without_fabricating_one(): void
    {
        $invalid = $this->meeting([
            'scheduled_start_at' => '2026-10-03 03:13:13',
            'scheduled_end_at' => '2026-09-20 10:14:03',
        ]);
        $valid = $this->meeting(['title' => 'Valid meeting']);

        $events = collect($this->actingAs($this->teacher)->getJson(route('calendar.events', [
            'view' => 'month',
            'start' => '2026-10-01T00:00:00Z',
            'end' => '2026-11-01T00:00:00Z',
        ]))->assertOk()->json('events'));

        $ids = $events->pluck('id')->all();

        $this->assertContains('meeting:'.$valid->uuid, $ids, 'a valid meeting still appears');
        $this->assertNotContains('meeting:'.$invalid->uuid, $ids,
            'an inverted interval must not be rendered as a calendar event');

        foreach ($events as $event) {
            if ($event['ends_at'] !== null) {
                $this->assertTrue(
                    \Carbon\Carbon::parse($event['ends_at'])->gt(\Carbon\Carbon::parse($event['starts_at'])),
                    'no calendar event may expose an inverted interval'
                );
            }
        }
    }

    public function test_calendar_keeps_ended_meetings_visible_for_a_valid_interval(): void
    {
        $ended = $this->meeting([
            'title' => 'Ended but valid',
            'status' => MeetingStatus::Ended,
            'actual_start_at' => '2026-10-10 06:05:00',
            'actual_end_at' => '2026-10-10 07:00:00',
        ]);

        $ids = collect($this->actingAs($this->teacher)->getJson(route('calendar.events', [
            'view' => 'month',
            'start' => '2026-10-01T00:00:00Z',
            'end' => '2026-11-01T00:00:00Z',
        ]))->assertOk()->json('events'))->pluck('id')->all();

        $this->assertContains('meeting:'.$ended->uuid, $ids,
            'BUG-06 behaviour must be preserved for meetings with a valid schedule');
    }
}
