<?php

namespace Tests\Feature\PhaseD;

use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MeetingAttendanceDurationTest extends TestCase
{
    use RefreshDatabase;

    private const OCCURRENCE = '2026-03-10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        \Carbon\CarbonImmutable::setTestNow(self::OCCURRENCE.' 11:00:00 UTC');
    }

    protected function tearDown(): void
    {
        \Carbon\CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_single_join_and_leave_produces_half_attendance(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class);
        $meeting = $this->endedMeeting($class);
        $participant = $this->participant($meeting, $student);
        $this->attendanceSession($participant, 'PA_SINGLE', '10:10', '10:40');

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        $this->assertSame(1, $row['sessions_count']);
        $this->assertSame(1800, $row['attended_seconds']);
        $this->assertEqualsWithDelta(50.0, $row['attendance_percentage'], 0.001);
        $this->assertSame('attended', $row['status']);
        $this->assertSame(
            \Carbon\Carbon::parse(self::OCCURRENCE.' 10:10:00')->toIso8601String(),
            $row['first_joined_at']
        );
        $this->assertSame(
            \Carbon\Carbon::parse(self::OCCURRENCE.' 10:40:00')->toIso8601String(),
            $row['last_left_at']
        );
    }

    public function test_reconnect_sums_separate_non_overlapping_sessions(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class);
        $meeting = $this->endedMeeting($class);
        $participant = $this->participant($meeting, $student);
        $this->attendanceSession($participant, 'PA_RECONNECT_A', '10:05', '10:20');
        $this->attendanceSession($participant, 'PA_RECONNECT_B', '10:30', '10:50');

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        $this->assertSame(2, $row['sessions_count']);
        $this->assertSame(2100, $row['attended_seconds']);
        $this->assertEqualsWithDelta(58.33, $row['attendance_percentage'], 0.001);
    }

    public function test_overlapping_sessions_are_merged_and_not_double_counted(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class);
        $meeting = $this->endedMeeting($class);
        $participant = $this->participant($meeting, $student);
        $this->attendanceSession($participant, 'PA_OVERLAP_A', '10:10', '10:40');
        $this->attendanceSession($participant, 'PA_OVERLAP_B', '10:30', '10:50');

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        // A naive sum would be 1800 + 1200 = 3000 seconds.
        $this->assertSame(2, $row['sessions_count']);
        $this->assertSame(2400, $row['attended_seconds']);
        $this->assertEqualsWithDelta(66.67, $row['attendance_percentage'], 0.001);
    }

    public function test_touching_intervals_merge_without_double_counting(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class);
        $meeting = $this->endedMeeting($class);
        $participant = $this->participant($meeting, $student);
        $this->attendanceSession($participant, 'PA_TOUCH_A', '10:10', '10:20');
        $this->attendanceSession($participant, 'PA_TOUCH_B', '10:20', '10:30');

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        $this->assertSame(2, $row['sessions_count']);
        $this->assertSame(1200, $row['attended_seconds']);
        $this->assertEqualsWithDelta(33.33, $row['attendance_percentage'], 0.001);
    }

    public function test_active_meeting_bounds_an_open_session_by_server_now(): void
    {
        \Carbon\CarbonImmutable::setTestNow(self::OCCURRENCE.' 10:45:00 UTC');

        $class = $this->activeClass();
        $student = $this->enrolledStudent($class);
        $meeting = $this->meeting($class, [
            'status' => MeetingStatus::Active,
            'actual_start_at' => self::OCCURRENCE.' 10:00:00',
            'actual_end_at' => null,
        ]);
        $participant = $this->participant($meeting, $student);
        $this->attendanceSession($participant, 'PA_OPEN', '10:15', null);

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        // Server now (10:45) bounds the window, not scheduled_end_at (12:00).
        $this->assertSame(2700, $props['meeting']['duration_seconds']);
        $this->assertTrue($props['meeting']['duration_is_authoritative']);
        $this->assertSame(1, $row['sessions_count']);
        $this->assertSame(1800, $row['attended_seconds']);
        $this->assertNull($row['last_left_at']);
        $this->assertEqualsWithDelta(66.67, $row['attendance_percentage'], 0.001);
    }

    public function test_ended_meeting_uses_actual_end_at_as_the_denominator(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class);
        $meeting = $this->meeting($class, [
            'status' => MeetingStatus::Ended,
            'scheduled_start_at' => self::OCCURRENCE.' 10:00:00',
            'scheduled_end_at' => self::OCCURRENCE.' 12:00:00',
            'session_started_at' => self::OCCURRENCE.' 10:00:00',
            'actual_start_at' => self::OCCURRENCE.' 10:00:00',
            'actual_end_at' => self::OCCURRENCE.' 11:00:00',
        ]);
        $participant = $this->participant($meeting, $student);
        $this->attendanceSession($participant, 'PA_ACTUAL_END', '10:00', '11:00');

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        // scheduled_end_at is 12:00, so a 3600-second duration proves it is ignored.
        $this->assertSame(3600, $props['meeting']['duration_seconds']);
        $this->assertSame(3600, $row['attended_seconds']);
        $this->assertEqualsWithDelta(100.0, $row['attendance_percentage'], 0.001);
    }

    public function test_attendance_percentage_never_exceeds_one_hundred(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class);
        $meeting = $this->endedMeeting($class);
        $participant = $this->participant($meeting, $student);
        $this->attendanceSession($participant, 'PA_OVERBURN', '09:30', '11:30');

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        $this->assertSame(3600, $props['meeting']['duration_seconds']);
        $this->assertEqualsWithDelta(100.0, $row['attendance_percentage'], 0.001);
    }

    public function test_zero_duration_meeting_does_not_divide_by_zero(): void
    {
        $class = $this->activeClass();
        $this->enrolledStudent($class);
        $meeting = $this->meeting($class, [
            'status' => MeetingStatus::Ended,
            'scheduled_start_at' => self::OCCURRENCE.' 10:00:00',
            'scheduled_end_at' => self::OCCURRENCE.' 10:00:00',
            'session_started_at' => self::OCCURRENCE.' 10:00:00',
            'actual_start_at' => self::OCCURRENCE.' 10:00:00',
            'actual_end_at' => self::OCCURRENCE.' 10:00:00',
        ]);

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        $this->assertSame(0, $props['meeting']['duration_seconds']);
        $this->assertEqualsWithDelta(0.0, $row['attendance_percentage'], 0.001);
    }

    public function test_legacy_meeting_without_a_session_start_reports_no_percentage(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class);
        $meeting = $this->meeting($class, [
            'status' => MeetingStatus::Ended,
            'scheduled_start_at' => self::OCCURRENCE.' 10:00:00',
            'scheduled_end_at' => self::OCCURRENCE.' 11:00:00',
            'session_started_at' => null,
            'actual_start_at' => self::OCCURRENCE.' 10:00:00',
            'actual_end_at' => self::OCCURRENCE.' 11:00:00',
        ]);
        $participant = $this->participant($meeting, $student);
        $this->attendanceSession($participant, 'PA_LEGACY', '10:10', '10:40');

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        $this->assertSame(1800, $row['attended_seconds']);
        $this->assertFalse($props['meeting']['duration_is_authoritative']);
        $this->assertNull($props['meeting']['duration_seconds']);
        $this->assertNull($row['attendance_percentage']);
    }

    public function test_cancelled_meeting_is_not_reported_as_absence(): void
    {
        $class = $this->activeClass();
        $this->enrolledStudent($class);
        $meeting = $this->meeting($class, [
            'status' => MeetingStatus::Cancelled,
            'session_started_at' => null,
            'actual_start_at' => null,
            'actual_end_at' => null,
        ]);

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        $this->assertSame('not_applicable', $row['status']);
        $this->assertNotSame('absent', $row['status']);
        $this->assertNull($row['attendance_percentage']);
        $this->assertSame(1, $props['summary']['not_applicable_count']);
        $this->assertSame(0, $props['summary']['absent_count']);
    }

    public function test_interval_with_left_at_before_joined_at_is_ignored(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class);
        $meeting = $this->endedMeeting($class);
        $participant = $this->participant($meeting, $student);
        $this->attendanceSession($participant, 'PA_INVERTED', '10:40', '10:30');

        $props = $this->reportProps($class, $meeting);
        $row = $this->onlyRow($props);

        $this->assertSame(1, $row['sessions_count']);
        $this->assertSame(0, $row['attended_seconds']);
        $this->assertEqualsWithDelta(0.0, $row['attendance_percentage'], 0.001);
        $this->assertSame('absent', $row['status']);
    }

    /** @return array<string, mixed> */
    private function reportProps(SchoolClass $class, Meeting $meeting): array
    {
        $response = $this->actingAs($this->admin())
            ->get(route('meetings.attendance.show', [$class, $meeting]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Meetings/Attendance'));

        return $this->props($response);
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function onlyRow(array $props): array
    {
        $this->assertCount(1, $props['rows'], 'Expected exactly one roster row for this fixture.');

        return $props['rows'][0];
    }

    /** @param array<string, mixed> $overrides */
    private function meeting(SchoolClass $class, array $overrides = []): Meeting
    {
        return Meeting::factory()->create([
            'school_class_id' => $class->id,
            'class_subject_id' => null,
            'status' => MeetingStatus::Ended,
            'scheduled_start_at' => self::OCCURRENCE.' 10:00:00',
            'scheduled_end_at' => self::OCCURRENCE.' 11:00:00',
            'session_started_at' => self::OCCURRENCE.' 10:00:00',
            'actual_start_at' => self::OCCURRENCE.' 10:00:00',
            'actual_end_at' => self::OCCURRENCE.' 11:00:00',
            ...$overrides,
        ]);
    }

    private function endedMeeting(SchoolClass $class): Meeting
    {
        return $this->meeting($class);
    }

    private function participant(Meeting $meeting, User $student): MeetingParticipant
    {
        return MeetingParticipant::factory()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $student->id,
        ]);
    }

    private function attendanceSession(MeetingParticipant $participant, string $sid, ?string $joinedAt, ?string $leftAt): MeetingAttendanceSession
    {
        return MeetingAttendanceSession::factory()->create([
            'meeting_participant_id' => $participant->id,
            'livekit_participant_sid' => $sid,
            'joined_at' => $joinedAt === null ? null : self::OCCURRENCE.' '.$joinedAt.':00',
            'left_at' => $leftAt === null ? null : self::OCCURRENCE.' '.$leftAt.':00',
        ]);
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create([
            'academic_year_id' => AcademicYear::factory()->active(),
            'status' => SchoolClassStatus::Active,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        return $user;
    }

    private function enrolledStudent(SchoolClass $class): User
    {
        $user = User::factory()->create();
        $user->assignRole('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
            'enrolled_on' => '2026-01-01',
            'ended_on' => null,
        ]);

        return $user;
    }
}
