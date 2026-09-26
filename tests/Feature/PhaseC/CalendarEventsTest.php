<?php

namespace Tests\Feature\PhaseC;

use App\Enums\AcademicYearStatus;
use App\Enums\AssignmentStatus;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_calendar_range_includes_only_overlapping_meetings_and_in_range_assignments(): void
    {
        [$class, $subject] = $this->classAndSubject();
        $teacher = $this->teacher($class, $subject);

        $beforeMeeting = $this->meeting($class, $subject, $teacher, 'Before meeting', '2026-09-29 08:00:00', '2026-09-29 09:00:00');
        $overlapMeeting = $this->meeting($class, $subject, $teacher, 'Overlap meeting', '2026-09-30 23:30:00', '2026-10-01 00:30:00');
        $insideMeeting = $this->meeting($class, $subject, $teacher, 'Inside meeting', '2026-10-05 08:00:00', '2026-10-05 09:00:00');
        $afterMeeting = $this->meeting($class, $subject, $teacher, 'After meeting', '2026-10-10 00:00:00', '2026-10-10 01:00:00');

        $beforeAssignment = $this->assignment($subject, $teacher, 'Before assignment', '2026-09-30 23:59:59');
        $insideAssignment = $this->assignment($subject, $teacher, 'Inside assignment', '2026-10-06 12:00:00');
        $afterAssignment = $this->assignment($subject, $teacher, 'After assignment', '2026-10-10 00:00:00');

        $response = $this->actingAs($teacher)->getJson(route('calendar.events', [
            'view' => 'month',
            'start' => '2026-10-01T00:00:00Z',
            'end' => '2026-10-10T00:00:00Z',
        ]))->assertOk();

        $ids = collect($response->json('events'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([
            'meeting:'.$overlapMeeting->uuid,
            'meeting:'.$insideMeeting->uuid,
            'assignment:'.$insideAssignment->id,
        ], $ids);
        $this->assertNotContains('meeting:'.$beforeMeeting->uuid, $ids);
        $this->assertNotContains('meeting:'.$afterMeeting->uuid, $ids);
        $this->assertNotContains('assignment:'.$beforeAssignment->id, $ids);
        $this->assertNotContains('assignment:'.$afterAssignment->id, $ids);
        $this->assertEqualsCanonicalizing(
            ['meeting', 'assignment'],
            collect($response->json('events'))->pluck('type')->unique()->values()->all(),
        );
    }

    public function test_calendar_visibility_follows_teacher_student_and_admin_authorization(): void
    {
        [$visibleClass, $visibleSubject] = $this->classAndSubject();
        $teacher = $this->teacher($visibleClass, $visibleSubject);
        $student = $this->student($visibleClass);
        $unrelatedTeacher = $this->roleUser('Teacher');
        $admin = $this->roleUser('Admin');

        $otherClass = SchoolClass::factory()->create([
            'academic_year_id' => $visibleClass->academic_year_id,
            'status' => SchoolClassStatus::Active,
        ]);
        $otherSubject = ClassSubject::factory()->create(['school_class_id' => $otherClass->id]);
        $otherTeacher = $this->teacher($otherClass, $otherSubject);

        $visibleMeeting = $this->meeting($visibleClass, $visibleSubject, $teacher, 'Visible meeting', '2026-10-05 08:00:00', '2026-10-05 09:00:00');
        $hiddenMeeting = $this->meeting($otherClass, $otherSubject, $otherTeacher, 'Hidden meeting', '2026-10-05 10:00:00', '2026-10-05 11:00:00');
        $visibleAssignment = $this->assignment($visibleSubject, $teacher, 'Visible assignment', '2026-10-06 12:00:00');
        $hiddenAssignment = $this->assignment($otherSubject, $otherTeacher, 'Hidden assignment', '2026-10-07 12:00:00');

        $expectedScoped = ['meeting:'.$visibleMeeting->uuid, 'assignment:'.$visibleAssignment->id];
        foreach ([$teacher, $student] as $user) {
            $ids = collect($this->calendar($user)->assertOk()->json('events'))->pluck('id')->all();
            $this->assertEqualsCanonicalizing($expectedScoped, $ids);
            $this->assertNotContains('meeting:'.$hiddenMeeting->uuid, $ids);
            $this->assertNotContains('assignment:'.$hiddenAssignment->id, $ids);
        }

        $this->assertSame([], $this->calendar($unrelatedTeacher)->assertOk()->json('events'));

        $adminIds = collect($this->calendar($admin)->assertOk()->json('events'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([
            ...$expectedScoped,
            'meeting:'.$hiddenMeeting->uuid,
            'assignment:'.$hiddenAssignment->id,
        ], $adminIds);
    }

    public function test_calendar_rejects_unbounded_ranges_and_never_exposes_meeting_credentials(): void
    {
        [$class, $subject] = $this->classAndSubject();
        $teacher = $this->teacher($class, $subject);
        $meeting = $this->meeting($class, $subject, $teacher, 'Credential-safe meeting', '2026-10-05 08:00:00', '2026-10-05 09:00:00', [
            'livekit_room_name' => 'private-room-name',
        ]);

        $response = $this->calendar($teacher)->assertOk();
        $events = $response->json('events');
        $this->assertCount(1, $events);
        $this->assertSame('meeting:'.$meeting->uuid, $events[0]['id']);

        foreach ($events as $event) {
            $this->assertSame([], array_intersect([
                'livekit_room_name',
                'livekit_api_key',
                'livekit_api_secret',
                'participant_token',
                'access_token',
                'token',
                'secret',
            ], array_keys($event)));
        }

        $json = $response->getContent();
        $this->assertStringNotContainsString('private-room-name', $json);
        $this->assertStringNotContainsString('livekit_api_key', $json);
        $this->assertStringNotContainsString('livekit_api_secret', $json);

        $this->actingAs($teacher)->getJson(route('calendar.events', [
            'view' => 'month',
            'start' => '2026-01-01T00:00:00Z',
            'end' => '2026-12-31T00:00:00Z',
        ]))->assertUnprocessable()->assertJsonValidationErrors('end');
    }

    public function test_date_only_month_boundaries_are_resolved_in_the_configured_calendar_timezone(): void
    {
        config(['calendar.default_timezone' => 'Asia/Phnom_Penh']);

        [$class, $subject] = $this->classAndSubject();
        $teacher = $this->teacher($class, $subject);

        $firstAcademicMinute = $this->meeting(
            $class, $subject, $teacher, 'First academic minute', '2026-09-30T17:30:00Z', '2026-09-30T18:00:00Z',
        );
        $spanningMeeting = $this->meeting(
            $class, $subject, $teacher, 'Spanning meeting', '2026-09-29T10:00:00Z', '2026-10-05T10:00:00Z',
        );
        $earlierMeeting = $this->meeting(
            $class, $subject, $teacher, 'Earlier meeting', '2026-09-20T08:00:00Z', '2026-09-20T09:00:00Z',
        );

        $boundaryAssignment = $this->assignment($subject, $teacher, 'Boundary assignment', '2026-09-30T16:59:00Z');
        $insideAssignment = $this->assignment($subject, $teacher, 'Inside assignment', '2026-10-05T04:00:00Z');

        $response = $this->actingAs($teacher)->getJson(route('calendar.events', [
            'view' => 'month',
            'start' => '2026-10-01',
            'end' => '2026-11-01',
        ]))->assertOk();

        $this->assertSame('Asia/Phnom_Penh', $response->json('timezone'));
        $this->assertSame(
            '2026-09-30 17:00:00',
            CarbonImmutable::parse($response->json('range.start'))->utc()->toDateTimeString(),
        );
        $this->assertSame(
            '2026-10-31 17:00:00',
            CarbonImmutable::parse($response->json('range.end'))->utc()->toDateTimeString(),
        );

        $ids = collect($response->json('events'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([
            'meeting:'.$firstAcademicMinute->uuid,
            'meeting:'.$spanningMeeting->uuid,
            'assignment:'.$insideAssignment->id,
        ], $ids);
        $this->assertNotContains('meeting:'.$earlierMeeting->uuid, $ids);
        $this->assertNotContains('assignment:'.$boundaryAssignment->id, $ids);
    }

    public function test_explicit_iso_range_boundaries_keep_their_own_instants(): void
    {
        config(['calendar.default_timezone' => 'Asia/Phnom_Penh']);

        [$class, $subject] = $this->classAndSubject();
        $teacher = $this->teacher($class, $subject);

        $response = $this->actingAs($teacher)->getJson(route('calendar.events', [
            'view' => 'month',
            'start' => '2026-10-01T00:00:00+07:00',
            'end' => '2026-10-02T00:00:00+07:00',
        ]))->assertOk();

        $this->assertSame(
            '2026-09-30 17:00:00',
            CarbonImmutable::parse($response->json('range.start'))->utc()->toDateTimeString(),
        );
        $this->assertSame(
            '2026-10-01 17:00:00',
            CarbonImmutable::parse($response->json('range.end'))->utc()->toDateTimeString(),
        );
    }

    public function test_date_only_range_length_is_measured_in_calendar_days(): void
    {
        config(['calendar.default_timezone' => 'America/New_York']);

        [$class, $subject] = $this->classAndSubject();
        $teacher = $this->teacher($class, $subject);
        $this->meeting($class, $subject, $teacher, 'Spring forward meeting', '2026-03-01T12:00:00Z', '2026-03-01T13:00:00Z');

        // 2026-03-08 is the US DST start; a 93-calendar-day range still contains
        // 92h-based 93 days minus an hour, so it must not be rejected.
        $this->actingAs($teacher)->getJson(route('calendar.events', [
            'view' => 'month',
            'start' => '2026-03-01',
            'end' => '2026-06-02',
        ]))->assertOk();

        $this->actingAs($teacher)->getJson(route('calendar.events', [
            'view' => 'month',
            'start' => '2026-03-01',
            'end' => '2026-06-03',
        ]))->assertUnprocessable()->assertJsonValidationErrors('end');
    }

    private function calendar(User $user)
    {
        return $this->actingAs($user)->getJson(route('calendar.events', [
            'view' => 'month',
            'start' => '2026-10-01T00:00:00Z',
            'end' => '2026-10-10T00:00:00Z',
        ]));
    }

    private function classAndSubject(): array
    {
        $year = AcademicYear::factory()->create([
            'name' => '2026/2028',
            'starts_on' => '2026-01-01',
            'ends_on' => '2028-12-31',
            'status' => AcademicYearStatus::Active,
            'active_slot' => 1,
        ]);
        $class = SchoolClass::factory()->create([
            'academic_year_id' => $year->id,
            'status' => SchoolClassStatus::Active,
        ]);

        return [$class, ClassSubject::factory()->create(['school_class_id' => $class->id])];
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function teacher(SchoolClass $class, ClassSubject $subject): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'school_class_id' => $class->id,
            'starts_on' => '2026-01-01',
            'current_slot' => 1,
        ]);
        TeacherClassSubjectAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'class_subject_id' => $subject->id,
            'starts_on' => '2026-01-01',
            'current_slot' => 1,
        ]);

        return $user;
    }

    private function student(SchoolClass $class): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
            'enrolled_on' => '2026-01-01',
            'current_slot' => 1,
        ]);

        return $user;
    }

    private function meeting(
        SchoolClass $class,
        ClassSubject $subject,
        User $teacher,
        string $title,
        string $startsAt,
        string $endsAt,
        array $overrides = [],
    ): Meeting {
        return Meeting::factory()->create(array_merge([
            'school_class_id' => $class->id,
            'class_subject_id' => $subject->id,
            'created_by' => $teacher->id,
            'host_user_id' => $teacher->id,
            'title' => $title,
            'scheduled_start_at' => $startsAt,
            'scheduled_end_at' => $endsAt,
            'status' => MeetingStatus::Scheduled,
        ], $overrides));
    }

    private function assignment(ClassSubject $subject, User $teacher, string $title, string $dueAt): Assignment
    {
        return Assignment::factory()->create([
            'class_subject_id' => $subject->id,
            'created_by' => $teacher->id,
            'title' => $title,
            'due_at' => $dueAt,
            'status' => AssignmentStatus::Published,
            'published_at' => '2026-09-20 00:00:00',
        ]);
    }
}
