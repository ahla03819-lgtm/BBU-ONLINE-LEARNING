<?php

namespace Tests\Feature\PhaseD;

use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Testing\TestResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingAttendanceRosterTest extends TestCase
{
    use RefreshDatabase;

    private const OCCURRENCE = '2026-03-10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        \Carbon\CarbonImmutable::setTestNow('2026-03-20 10:00:00 UTC');
    }

    protected function tearDown(): void
    {
        \Carbon\CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_never_joined_student_appears_with_zero_attendance(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class, '2026-01-01', null, 'S-NEVER-JOINED');
        $meeting = $this->occurrenceMeeting($class);

        $props = $this->reportProps($class, $meeting);
        $row = $this->rowFor($props['rows'], 'S-NEVER-JOINED');

        $this->assertNotNull($row, 'A never-joined enrolled student must still appear in the roster.');
        $this->assertSame($student->name, $row['student_name']);
        $this->assertSame(0, $row['sessions_count']);
        $this->assertSame(0, $row['attended_seconds']);
        $this->assertNull($row['first_joined_at']);
        $this->assertNull($row['last_left_at']);
        $this->assertSame('absent', $row['status']);
        $this->assertEqualsWithDelta(0.0, $row['attendance_percentage'], 0.0001);
    }

    public function test_student_enrolled_after_the_occurrence_does_not_appear(): void
    {
        $class = $this->activeClass();
        $this->enrolledStudent($class, '2026-03-11', null, 'S-LATE-ENROLLEE');
        $meeting = $this->occurrenceMeeting($class);

        $props = $this->reportProps($class, $meeting);

        $this->assertNull($this->rowFor($props['rows'], 'S-LATE-ENROLLEE'));
        $this->assertSame(0, $props['summary']['roster_count']);
    }

    public function test_student_whose_enrollment_ended_before_the_occurrence_does_not_appear(): void
    {
        $class = $this->activeClass();
        $this->enrolledStudent($class, '2026-01-01', '2026-03-09', 'S-LEFT-EARLY');
        $meeting = $this->occurrenceMeeting($class);

        $props = $this->reportProps($class, $meeting);

        $this->assertNull($this->rowFor($props['rows'], 'S-LEFT-EARLY'));
        $this->assertSame(0, $props['summary']['roster_count']);
    }

    public function test_enrollment_ending_exactly_on_the_occurrence_date_appears(): void
    {
        $class = $this->activeClass();
        $this->enrolledStudent($class, '2026-01-01', '2026-03-10', 'S-ENDS-ON-DAY');
        $meeting = $this->occurrenceMeeting($class);

        $props = $this->reportProps($class, $meeting);

        $this->assertNotNull($this->rowFor($props['rows'], 'S-ENDS-ON-DAY'));
        $this->assertSame(1, $props['summary']['roster_count']);
    }

    public function test_enrollment_starting_exactly_on_the_occurrence_date_appears(): void
    {
        $class = $this->activeClass();
        $this->enrolledStudent($class, '2026-03-10', null, 'S-STARTS-ON-DAY');
        $meeting = $this->occurrenceMeeting($class);

        $props = $this->reportProps($class, $meeting);

        $this->assertNotNull($this->rowFor($props['rows'], 'S-STARTS-ON-DAY'));
        $this->assertSame(1, $props['summary']['roster_count']);
    }

    public function test_student_from_another_school_class_does_not_appear(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create([
            'academic_year_id' => $class->academic_year_id,
            'status' => SchoolClassStatus::Active,
        ]);
        $this->enrolledStudent($otherClass, '2026-01-01', null, 'S-OTHER-CLASS');
        $meeting = $this->occurrenceMeeting($class);

        $props = $this->reportProps($class, $meeting);

        $this->assertNull($this->rowFor($props['rows'], 'S-OTHER-CLASS'));
        $this->assertSame(0, $props['summary']['roster_count']);
    }

    public function test_enrollment_from_another_academic_year_does_not_leak_into_the_report(): void
    {
        $class = $this->activeClass();
        // A second active academic year is impossible (active_slot is unique),
        // so the comparison year is created without an active slot.
        $otherYear = AcademicYear::factory()->create(['name' => '2019/2020']);
        $otherClass = SchoolClass::factory()->create([
            'academic_year_id' => $otherYear->id,
            'status' => SchoolClassStatus::Active,
        ]);
        $this->enrolledStudent($otherClass, '2026-01-01', null, 'S-OTHER-YEAR');
        $this->enrolledStudent($class, '2026-01-01', null, 'S-TARGET-YEAR');
        $meeting = $this->occurrenceMeeting($class);

        $props = $this->reportProps($class, $meeting);

        $this->assertNull($this->rowFor($props['rows'], 'S-OTHER-YEAR'));
        $this->assertNotNull($this->rowFor($props['rows'], 'S-TARGET-YEAR'));
        $this->assertSame(1, $props['summary']['roster_count']);
    }

    public function test_multiple_valid_historical_students_all_appear_without_participant_rows(): void
    {
        $class = $this->activeClass();
        foreach (['S-HIST-1', 'S-HIST-2', 'S-HIST-3'] as $studentNumber) {
            $this->enrolledStudent($class, '2026-01-01', null, $studentNumber);
        }
        $meeting = $this->occurrenceMeeting($class);

        $this->assertDatabaseCount('meeting_participants', 0);

        $props = $this->reportProps($class, $meeting);

        $this->assertSame(3, $props['summary']['roster_count']);
        foreach (['S-HIST-1', 'S-HIST-2', 'S-HIST-3'] as $studentNumber) {
            $row = $this->rowFor($props['rows'], $studentNumber);
            $this->assertNotNull($row, "Expected {$studentNumber} in the roster.");
            $this->assertSame(0, $row['sessions_count']);
            $this->assertSame(0, $row['attended_seconds']);
        }
    }

    public function test_report_route_returns_not_found_when_meeting_belongs_to_another_school_class(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create([
            'academic_year_id' => $class->academic_year_id,
            'status' => SchoolClassStatus::Active,
        ]);
        $meeting = $this->occurrenceMeeting($class);

        $this->actingAs($this->admin())
            ->get(route('meetings.attendance.show', [$otherClass, $meeting]))
            ->assertNotFound();
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
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function rowFor(array $rows, string $studentNumber): ?array
    {
        foreach ($rows as $row) {
            if (($row['student_number'] ?? null) === $studentNumber) {
                return $row;
            }
        }

        return null;
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

    private function enrolledStudent(SchoolClass $class, string $enrolledOn, ?string $endedOn, string $studentNumber): User
    {
        $user = User::factory()->create();
        $user->assignRole('Student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $user->id,
            'student_number' => $studentNumber,
        ]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
            'enrolled_on' => $enrolledOn,
            'ended_on' => $endedOn,
        ]);

        return $user;
    }

    private function occurrenceMeeting(SchoolClass $class, ?int $classSubjectId = null): Meeting
    {
        return Meeting::factory()->create([
            'school_class_id' => $class->id,
            'class_subject_id' => $classSubjectId,
            'status' => MeetingStatus::Ended,
            'scheduled_start_at' => self::OCCURRENCE.' 10:00:00',
            'scheduled_end_at' => self::OCCURRENCE.' 11:00:00',
            'session_started_at' => self::OCCURRENCE.' 10:00:00',
            'actual_start_at' => self::OCCURRENCE.' 10:00:00',
            'actual_end_at' => self::OCCURRENCE.' 11:00:00',
        ]);
    }
}
