<?php

namespace Tests\Feature\PhaseD;

use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\LiveKitWebhookEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MeetingAttendanceCsvTest extends TestCase
{
    use RefreshDatabase;

    private const OCCURRENCE = '2026-03-10';

    private const TITLE = 'Weekly Algebra Review';

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

    public function test_authorized_admin_can_download_the_csv(): void
    {
        $class = $this->activeClass();
        $this->enrolledStudent($class, 'CSV-ATTENDED');
        $meeting = $this->endedMeeting($class);

        $response = $this->actingAs($this->admin())
            ->get(route('meetings.attendance.export', [$class, $meeting]));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('meeting-attendance-weekly-algebra-review-'.self::OCCURRENCE.'.csv');
    }

    public function test_csv_contains_the_implemented_column_headings(): void
    {
        $class = $this->activeClass();
        $this->enrolledStudent($class, 'CSV-HEADINGS');
        $meeting = $this->endedMeeting($class);

        $rows = $this->exportRows($class, $meeting);

        $this->assertSame([
            'Student Number', 'Student Name', 'Attendance Status', 'First Joined At', 'Last Left At',
            'Sessions', 'Attended Seconds', 'Attended Duration', 'Meeting Duration', 'Attendance Percentage',
        ], $rows[0]);
    }

    public function test_csv_contains_the_joined_student_aggregate(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class, 'CSV-ATTENDED');
        $meeting = $this->endedMeeting($class);
        $participant = $this->participant($meeting, $student);
        $this->attendanceSession($participant, 'PA_CSV_JOINED', '10:15', '10:45');

        $row = $this->rowForNumber($class, $meeting, 'CSV-ATTENDED');

        $this->assertSame($student->name, $row['Student Name']);
        $this->assertSame('attended', $row['Attendance Status']);
        $this->assertSame('1', $row['Sessions']);
        $this->assertSame('1800', $row['Attended Seconds']);
        $this->assertSame('0:30:00', $row['Attended Duration']);
        $this->assertSame('1:00:00', $row['Meeting Duration']);
        $this->assertSame('50.00%', $row['Attendance Percentage']);
        $this->assertNotSame('', $row['First Joined At']);
        $this->assertNotSame('', $row['Last Left At']);
    }

    public function test_csv_contains_the_never_joined_student_from_enrollment_history(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class, 'CSV-NEVER-JOINED');
        $meeting = $this->endedMeeting($class);

        $row = $this->rowForNumber($class, $meeting, 'CSV-NEVER-JOINED');

        $this->assertSame($student->name, $row['Student Name']);
        $this->assertSame('absent', $row['Attendance Status']);
        $this->assertSame('0', $row['Sessions']);
        $this->assertSame('0', $row['Attended Seconds']);
        $this->assertSame('', $row['First Joined At']);
        $this->assertSame('', $row['Last Left At']);
    }

    public function test_export_denies_an_unrelated_teacher(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create([
            'academic_year_id' => $class->academic_year_id,
            'status' => SchoolClassStatus::Active,
        ]);
        $teacher = $this->classTeacher($otherClass);
        $meeting = $this->endedMeeting($class);

        $this->actingAs($teacher)
            ->get(route('meetings.attendance.export', [$class, $meeting]))
            ->assertForbidden();
    }

    public function test_export_denies_an_enrolled_student_despite_the_participants_permission(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class, 'CSV-STUDENT');
        $meeting = $this->endedMeeting($class);

        $this->assertTrue($student->can('meetings.participants.view'));

        $this->actingAs($student)
            ->get(route('meetings.attendance.export', [$class, $meeting]))
            ->assertForbidden();
    }

    public function test_export_returns_not_found_when_meeting_belongs_to_another_school_class(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create([
            'academic_year_id' => $class->academic_year_id,
            'status' => SchoolClassStatus::Active,
        ]);
        $meeting = $this->endedMeeting($class);

        $this->actingAs($this->admin())
            ->get(route('meetings.attendance.export', [$otherClass, $meeting]))
            ->assertNotFound();
    }

    public function test_csv_does_not_expose_provider_identifiers(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class, 'CSV-PROVIDER');
        $meeting = $this->endedMeeting($class);

        $identity = 'identity-CSV-SECRET-VALUE';
        $sid = 'PA_CSV_PROVIDER_SID';
        $joinEvent = LiveKitWebhookEvent::factory()->create(['event_type' => 'participant_joined']);
        $leaveEvent = LiveKitWebhookEvent::factory()->create(['event_type' => 'participant_left']);

        $participant = MeetingParticipant::factory()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $student->id,
            'livekit_identity' => $identity,
        ]);
        MeetingAttendanceSession::factory()->create([
            'meeting_participant_id' => $participant->id,
            'livekit_participant_sid' => $sid,
            'join_webhook_event_id' => $joinEvent->event_id,
            'leave_webhook_event_id' => $leaveEvent->event_id,
            'joined_at' => self::OCCURRENCE.' 10:15:00',
            'left_at' => self::OCCURRENCE.' 10:45:00',
        ]);

        $csv = $this->exportContent($class, $meeting);

        $this->assertStringContainsString('CSV-PROVIDER', $csv);
        $this->assertStringNotContainsString($identity, $csv);
        $this->assertStringNotContainsString($sid, $csv);
        $this->assertStringNotContainsString($joinEvent->event_id, $csv);
        $this->assertStringNotContainsString($leaveEvent->event_id, $csv);
        $this->assertStringNotContainsString('PA_CSV', $csv);
        $this->assertStringNotContainsString('identity-', $csv);
    }

    public function test_csv_neutralises_spreadsheet_formula_prefixes(): void
    {
        $class = $this->activeClass();
        $this->enrolledStudent($class, '=2+2');
        $meeting = $this->endedMeeting($class);

        $response = $this->actingAs($this->admin())
            ->get(route('meetings.attendance.export', [$class, $meeting]));

        $response->assertOk();

        $rows = $this->rowsFromResponse($response);
        $row = $this->rowWithNumber($rows, "'=2+2");

        $this->assertNotNull($row, 'The formula-prefixed value must still be exported, safely prefixed.');
        $this->assertStringNotContainsString('"=2+2"', $response->streamedContent());
        $this->assertStringContainsString("'=2+2", $response->streamedContent());
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function exportRows(SchoolClass $class, Meeting $meeting): array
    {
        return $this->rowsFromResponse(
            $this->actingAs($this->admin())
                ->get(route('meetings.attendance.export', [$class, $meeting]))
                ->assertOk()
        );
    }

    /** @return array<string, mixed> */
    private function rowForNumber(SchoolClass $class, Meeting $meeting, string $studentNumber): array
    {
        $rows = $this->exportRows($class, $meeting);
        $row = $this->rowWithNumber($rows, $studentNumber);

        $this->assertNotNull($row, "Expected a CSV row for {$studentNumber}.");

        return array_combine($rows[0], $row);
    }

    private function exportContent(SchoolClass $class, Meeting $meeting): string
    {
        return $this->actingAs($this->admin())
            ->get(route('meetings.attendance.export', [$class, $meeting]))
            ->assertOk()
            ->streamedContent();
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

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, string>|null
     */
    private function rowWithNumber(array $rows, string $studentNumber): ?array
    {
        foreach (array_slice($rows, 1) as $row) {
            if (($row[0] ?? null) === $studentNumber) {
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

    private function classTeacher(SchoolClass $class): User
    {
        $user = User::factory()->create();
        $user->assignRole('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'school_class_id' => $class->id,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-03-15',
            'current_slot' => 0,
        ]);

        return $user;
    }

    private function enrolledStudent(SchoolClass $class, string $studentNumber): User
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
            'enrolled_on' => '2026-01-01',
            'ended_on' => null,
        ]);

        return $user;
    }

    private function endedMeeting(SchoolClass $class, ?int $classSubjectId = null): Meeting
    {
        return Meeting::factory()->create([
            'school_class_id' => $class->id,
            'class_subject_id' => $classSubjectId,
            'title' => self::TITLE,
            'status' => MeetingStatus::Ended,
            'scheduled_start_at' => self::OCCURRENCE.' 10:00:00',
            'scheduled_end_at' => self::OCCURRENCE.' 11:00:00',
            'session_started_at' => self::OCCURRENCE.' 10:00:00',
            'actual_start_at' => self::OCCURRENCE.' 10:00:00',
            'actual_end_at' => self::OCCURRENCE.' 11:00:00',
        ]);
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
            'joined_at' => self::OCCURRENCE.' '.$joinedAt.':00',
            'left_at' => self::OCCURRENCE.' '.$leftAt.':00',
        ]);
    }
}
