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
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MeetingAttendanceAuthorizationTest extends TestCase
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

    public function test_admin_can_view_meeting_attendance_report(): void
    {
        $class = $this->activeClass();
        $meeting = $this->occurrenceMeeting($class);

        $this->actingAs($this->roleUser('Admin'))
            ->get(route('meetings.attendance.show', [$class, $meeting]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Meetings/Attendance'));
    }

    public function test_super_admin_can_view_meeting_attendance_report(): void
    {
        $class = $this->activeClass();
        $meeting = $this->occurrenceMeeting($class);

        $this->actingAs($this->roleUser('Super Admin'))
            ->get(route('meetings.attendance.show', [$class, $meeting]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Meetings/Attendance'));
    }

    public function test_historically_assigned_class_teacher_can_view_without_a_current_slot(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class, '2026-01-01', '2026-03-15', 0);
        $meeting = $this->occurrenceMeeting($class);

        $this->actingAs($teacher)
            ->get(route('meetings.attendance.show', [$class, $meeting]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Meetings/Attendance'));
    }

    public function test_historically_assigned_subject_teacher_can_view_a_subject_meeting_without_a_current_slot(): void
    {
        $class = $this->activeClass();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $teacher = $this->subjectTeacher($subject, '2026-01-01', '2026-03-15', 0);
        $meeting = $this->occurrenceMeeting($class, $subject->id);

        $this->actingAs($teacher)
            ->get(route('meetings.attendance.show', [$class, $meeting]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Meetings/Attendance'));
    }

    public function test_teacher_assignment_starting_after_the_occurrence_is_forbidden(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class, '2026-03-11', null, 0);
        $meeting = $this->occurrenceMeeting($class);

        $this->actingAs($teacher)
            ->get(route('meetings.attendance.show', [$class, $meeting]))
            ->assertForbidden();
    }

    public function test_teacher_assignment_ending_before_the_occurrence_is_forbidden(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class, '2026-01-01', '2026-03-09', 0);
        $meeting = $this->occurrenceMeeting($class);

        $this->actingAs($teacher)
            ->get(route('meetings.attendance.show', [$class, $meeting]))
            ->assertForbidden();
    }

    public function test_completely_unrelated_teacher_is_forbidden(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create([
            'academic_year_id' => $class->academic_year_id,
            'status' => SchoolClassStatus::Active,
        ]);
        $teacher = $this->classTeacher($otherClass, '2026-01-01', '2026-03-15', 0);
        $meeting = $this->occurrenceMeeting($class);

        $this->actingAs($teacher)
            ->get(route('meetings.attendance.show', [$class, $meeting]))
            ->assertForbidden();
    }

    public function test_student_is_forbidden_from_the_full_class_attendance_report_despite_participants_permission(): void
    {
        $class = $this->activeClass();
        $student = $this->enrolledStudent($class, '2026-01-05');
        $meeting = $this->occurrenceMeeting($class);

        // The coarse permission alone must never grant whole-class report access.
        $this->assertTrue($student->can('meetings.participants.view'));

        $this->actingAs($student)
            ->get(route('meetings.attendance.show', [$class, $meeting]))
            ->assertForbidden();
    }

    public function test_teacher_without_the_participants_permission_is_forbidden_even_when_historically_assigned(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class, '2026-01-01', '2026-03-15', 0);
        $meeting = $this->occurrenceMeeting($class);

        $this->assertTrue($teacher->can('meetings.participants.view'));

        // Revoked inside this test only; the production seeder is untouched.
        Role::findByName('Teacher')->revokePermissionTo('meetings.participants.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($teacher->fresh())
            ->get(route('meetings.attendance.show', [$class, $meeting]))
            ->assertForbidden();
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create([
            'academic_year_id' => AcademicYear::factory()->active(),
            'status' => SchoolClassStatus::Active,
        ]);
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function classTeacher(SchoolClass $class, string $startsOn, ?string $endsOn, int $currentSlot): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'school_class_id' => $class->id,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'current_slot' => $currentSlot,
        ]);

        return $user;
    }

    private function subjectTeacher(ClassSubject $subject, string $startsOn, ?string $endsOn, int $currentSlot): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassSubjectAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'class_subject_id' => $subject->id,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'current_slot' => $currentSlot,
        ]);

        return $user;
    }

    private function enrolledStudent(SchoolClass $class, string $enrolledOn): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
            'enrolled_on' => $enrolledOn,
            'ended_on' => null,
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
