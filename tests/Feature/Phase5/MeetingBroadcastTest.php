<?php

namespace Tests\Feature\Phase5;

use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Events\MeetingLifecycleChanged;
use App\Events\MeetingParticipantRemoved;
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
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_class_channel_allows_only_current_relationships_and_administrators(): void
    {
        $class = $this->activeClass();
        $currentStudent = $this->student($class);
        $historicalStudent = $this->student($class, false);
        $currentTeacher = $this->teacher($class);
        $historicalTeacher = $this->teacher($class, false);
        $admin = $this->roleUser('Admin');
        $channel = app(BroadcastManager::class)->driver()->getChannels()['meetings.class.{schoolClassId}'];
        $this->assertTrue($channel($currentStudent, $class->id));
        $this->assertTrue($channel($currentTeacher, $class->id));
        $this->assertTrue($channel($admin, $class->id));
        $this->assertFalse($channel($historicalStudent, $class->id));
        $this->assertFalse($channel($historicalTeacher, $class->id));
    }

    public function test_lifecycle_and_removal_events_are_after_commit_class_scoped_and_secret_safe(): void
    {
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $this->activeClass()->id, 'lifecycle_version' => 9]);
        $participant = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id]);
        $lifecycle = new MeetingLifecycleChanged($meeting, 'started');
        $removal = new MeetingParticipantRemoved($participant);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $lifecycle);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $removal);
        $this->assertSame('private-meetings.class.'.$meeting->school_class_id, $lifecycle->broadcastOn()[0]->name);
        $payload = $lifecycle->broadcastWith();
        $this->assertSame(9, $payload['meeting']['lifecycle_version']);
        $this->assertNull($payload['meeting']['session_started_at']);
        $meeting->update(['session_started_at' => now()->subMinute()->startOfSecond()]);
        $this->assertSame($meeting->session_started_at->toIso8601String(), (new MeetingLifecycleChanged($meeting, 'started'))->broadcastWith()['meeting']['session_started_at']);
        $meeting->update(['status' => MeetingStatus::Ended]);
        $this->assertNull((new MeetingLifecycleChanged($meeting, 'ended'))->broadcastWith()['meeting']['session_started_at']);
        foreach (['token', 'livekit_room_name', 'livekit_identity', 'participant_sid', 'api_secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, json_encode($payload, JSON_THROW_ON_ERROR));
        }
        $this->assertSame($participant->public_uuid, $removal->broadcastWith()['participant']['reference']);
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
