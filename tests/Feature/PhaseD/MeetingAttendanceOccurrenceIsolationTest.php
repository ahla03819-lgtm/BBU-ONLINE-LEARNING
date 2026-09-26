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
use App\Models\MeetingSeries;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MeetingAttendanceOccurrenceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const OCCURRENCE_A = '2026-03-10';

    private const OCCURRENCE_B = '2026-03-17';

    private const TITLE = 'Weekly Algebra Review';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        \Carbon\CarbonImmutable::setTestNow('2026-03-20 11:00:00 UTC');
    }

    protected function tearDown(): void
    {
        \Carbon\CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_attendance_belongs_to_the_concrete_occurrence_and_does_not_bleed_across_the_series(): void
    {
        [$class, $series, $student] = $this->seriesFixture();
        $occurrenceA = $this->occurrence($class, $series, self::OCCURRENCE_A);
        $occurrenceB = $this->occurrence($class, $series, self::OCCURRENCE_B);
        $participantA = $this->participant($occurrenceA, $student);
        $this->attendanceSession($participantA, 'PA_OCC_A_SINGLE', '10:15', '10:45');

        $rowA = $this->onlyRow($this->reportProps($class, $occurrenceA));
        $rowB = $this->onlyRow($this->reportProps($class, $occurrenceB));

        $this->assertSame('attended', $rowA['status']);
        $this->assertSame(1, $rowA['sessions_count']);
        $this->assertSame(1800, $rowA['attended_seconds']);

        // The same enrolled student is still on occurrence B's roster, with no attendance.
        $this->assertSame($rowA['student_number'], $rowB['student_number']);
        $this->assertSame($rowA['student_name'], $rowB['student_name']);
        $this->assertSame('absent', $rowB['status']);
        $this->assertSame(0, $rowB['sessions_count']);
        $this->assertSame(0, $rowB['attended_seconds']);
    }

    public function test_reconnect_sessions_on_one_occurrence_do_not_affect_the_other(): void
    {
        [$class, $series, $student] = $this->seriesFixture();
        $occurrenceA = $this->occurrence($class, $series, self::OCCURRENCE_A);
        $occurrenceB = $this->occurrence($class, $series, self::OCCURRENCE_B);
        $participantA = $this->participant($occurrenceA, $student);
        $this->attendanceSession($participantA, 'PA_OCC_RECONNECT_A', '10:05', '10:20');
        $this->attendanceSession($participantA, 'PA_OCC_RECONNECT_B', '10:30', '10:50');

        $rowA = $this->onlyRow($this->reportProps($class, $occurrenceA));
        $rowB = $this->onlyRow($this->reportProps($class, $occurrenceB));

        $this->assertSame(2, $rowA['sessions_count']);
        $this->assertSame(2100, $rowA['attended_seconds']);
        $this->assertEqualsWithDelta(58.33, $rowA['attendance_percentage'], 0.001);

        $this->assertSame(0, $rowB['sessions_count']);
        $this->assertSame(0, $rowB['attended_seconds']);
    }

    public function test_csv_export_is_also_scoped_to_the_concrete_occurrence(): void
    {
        [$class, $series, $student] = $this->seriesFixture();
        $occurrenceA = $this->occurrence($class, $series, self::OCCURRENCE_A);
        $occurrenceB = $this->occurrence($class, $series, self::OCCURRENCE_B);
        $participantA = $this->participant($occurrenceA, $student);
        $this->attendanceSession($participantA, 'PA_OCC_CSV', '10:15', '10:45');

        $studentNumber = $student->studentProfile->student_number;
        $rowA = $this->csvRow($class, $occurrenceA, $studentNumber);
        $rowB = $this->csvRow($class, $occurrenceB, $studentNumber);

        $this->assertSame('attended', $rowA['Attendance Status']);
        $this->assertSame('1', $rowA['Sessions']);
        $this->assertSame('1800', $rowA['Attended Seconds']);

        $this->assertSame('absent', $rowB['Attendance Status']);
        $this->assertSame('0', $rowB['Sessions']);
        $this->assertSame('0', $rowB['Attended Seconds']);
    }

    public function test_series_relationship_is_shared_but_each_report_reads_only_its_own_meeting(): void
    {
        [$class, $series, $student] = $this->seriesFixture();
        $occurrenceA = $this->occurrence($class, $series, self::OCCURRENCE_A);
        $occurrenceB = $this->occurrence($class, $series, self::OCCURRENCE_B);
        $participantA = $this->participant($occurrenceA, $student);
        $this->attendanceSession($participantA, 'PA_OCC_SERIES', '10:15', '10:45');

        $this->assertNotSame($occurrenceA->id, $occurrenceB->id);
        $this->assertNotSame($occurrenceA->uuid, $occurrenceB->uuid);
        $this->assertSame($series->id, $occurrenceA->meeting_series_id);
        $this->assertSame($series->id, $occurrenceB->meeting_series_id);
        $this->assertNotSame($occurrenceA->series_occurrence_on->toDateString(), $occurrenceB->series_occurrence_on->toDateString());
        $this->assertDatabaseCount('meeting_participants', 1);
        $this->assertDatabaseCount('meeting_attendance_sessions', 1);

        // Both occurrences share one series, yet each report is derived from the
        // concrete meeting's own participant and session rows.
        $propsA = $this->reportProps($class, $occurrenceA);
        $propsB = $this->reportProps($class, $occurrenceB);

        $this->assertSame(self::OCCURRENCE_A, $propsA['meeting']['occurrence_date']);
        $this->assertSame(self::OCCURRENCE_B, $propsB['meeting']['occurrence_date']);
        $this->assertSame(1, $propsA['summary']['attended_count']);
        $this->assertSame(0, $propsB['summary']['attended_count']);
        $this->assertSame(1, $propsB['summary']['absent_count']);
    }

    /**
     * @return array{0: SchoolClass, 1: MeetingSeries, 2: User}
     */
    private function seriesFixture(): array
    {
        $class = $this->activeClass();
        $series = MeetingSeries::factory()->create([
            'school_class_id' => $class->id,
            'title' => self::TITLE,
            'starts_on' => self::OCCURRENCE_A,
            'ends_on' => self::OCCURRENCE_B,
        ]);
        $student = $this->enrolledStudent($class);

        return [$class, $series, $student];
    }

    private function occurrence(SchoolClass $class, MeetingSeries $series, string $date): Meeting
    {
        return Meeting::factory()->create([
            'meeting_series_id' => $series->id,
            'series_occurrence_on' => $date,
            'school_class_id' => $class->id,
            'class_subject_id' => null,
            'title' => self::TITLE,
            'status' => MeetingStatus::Ended,
            'scheduled_start_at' => $date.' 10:00:00',
            'scheduled_end_at' => $date.' 11:00:00',
            'session_started_at' => $date.' 10:00:00',
            'actual_start_at' => $date.' 10:00:00',
            'actual_end_at' => $date.' 11:00:00',
        ]);
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

    /** @return array<string, mixed> */
    private function reportProps(SchoolClass $class, Meeting $meeting): array
    {
        return $this->props(
            $this->actingAs($this->admin())
                ->get(route('meetings.attendance.show', [$class, $meeting]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('Meetings/Attendance'))
        );
    }

    /** @return array<string, mixed> */
    private function csvRow(SchoolClass $class, Meeting $meeting, string $studentNumber): array
    {
        $rows = $this->rowsFromResponse(
            $this->actingAs($this->admin())
                ->get(route('meetings.attendance.export', [$class, $meeting]))
                ->assertOk()
        );

        foreach (array_slice($rows, 1) as $row) {
            if (($row[0] ?? null) === $studentNumber) {
                return array_combine($rows[0], $row);
            }
        }

        $this->fail("Expected a CSV row for {$studentNumber}.");
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function rowsFromResponse(TestResponse $response): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($response->streamedContent())) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = str_getcsv($line);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
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
        $profile = StudentProfile::factory()->create([
            'user_id' => $user->id,
            'student_number' => 'OCC-STUDENT',
        ]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
            'enrolled_on' => '2026-01-01',
            'ended_on' => null,
        ]);

        return $user;
    }

    private function participant(Meeting $meeting, User $student): MeetingParticipant
    {
        return MeetingParticipant::factory()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $student->id,
        ]);
    }

    private function attendanceSession(MeetingParticipant $participant, string $sid, string $joinedAt, string $leftAt): MeetingAttendanceSession
    {
        return MeetingAttendanceSession::factory()->create([
            'meeting_participant_id' => $participant->id,
            'livekit_participant_sid' => $sid,
            'joined_at' => self::OCCURRENCE_A.' '.$joinedAt.':00',
            'left_at' => self::OCCURRENCE_A.' '.$leftAt.':00',
        ]);
    }
}
