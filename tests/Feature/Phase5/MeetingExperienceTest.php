<?php

namespace Tests\Feature\Phase5;

use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeetingExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_current_student_can_open_scheduled_lobby_without_join_authorization_or_technical_props(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id]);

        $this->actingAs($student)->get(route('meetings.lobby', [$class, $meeting]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Meetings/Lobby')->where('meeting.status', 'scheduled')->where('meeting.can_join', false)
            ->missing('meeting.livekit_room_name')->missing('meeting.livekit_identity')->missing('meeting.token'));
        $this->assertDatabaseCount('meeting_participants', 0);
    }

    public function test_active_current_student_and_teacher_can_open_room_but_historical_and_unrelated_users_are_denied(): void
    {
        $class = $this->activeClass();
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        foreach ([$this->student($class), $this->teacher($class)] as $current) {
            $this->actingAs($current)->get(route('meetings.room', [$class, $meeting]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Meetings/Room')->where('meeting.can_join', true));
        }
        foreach ([$this->student($class, false), $this->teacher($class, false), $this->roleUser('Student')] as $denied) {
            $this->actingAs($denied)->get(route('meetings.lobby', [$class, $meeting]))->assertForbidden();
        }
    }

    public function test_cross_class_nesting_is_not_found_for_lobby_and_room(): void
    {
        $class = $this->activeClass();
        $other = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        $this->actingAs($student)->get(route('meetings.lobby', [$other, $meeting]))->assertNotFound();
        $this->actingAs($student)->get(route('meetings.room', [$other, $meeting]))->assertNotFound();
    }

    public function test_removed_participant_is_denied_both_pages(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $student->id, 'removed_at' => now()]);
        $this->actingAs($student)->get(route('meetings.lobby', [$class, $meeting]))->assertForbidden();
        $this->actingAs($student)->get(route('meetings.room', [$class, $meeting]))->assertForbidden();
    }

    public function test_ended_and_cancelled_lobbies_are_read_only_and_rooms_are_denied(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        foreach ([MeetingStatus::Ended, MeetingStatus::Cancelled] as $status) {
            $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'status' => $status]);
            $this->actingAs($student)->get(route('meetings.lobby', [$class, $meeting]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('meeting.can_join', false));
            $this->actingAs($student)->get(route('meetings.room', [$class, $meeting]))->assertForbidden();
        }
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active]);
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function student(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create(['student_profile_id' => $profile->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => $current ? 1 : null, 'ended_on' => $current ? null : now()]);

        return $user;
    }

    private function teacher(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id, 'current_slot' => $current ? 1 : null, 'ends_on' => $current ? null : now()]);

        return $user;
    }
}
