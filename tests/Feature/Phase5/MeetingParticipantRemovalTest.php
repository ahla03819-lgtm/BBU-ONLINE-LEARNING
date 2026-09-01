<?php

namespace Tests\Feature\Phase5;

use App\Enums\MeetingProviderState;
use App\Enums\SchoolClassStatus;
use App\Events\MeetingParticipantRemoved;
use App\Models\AcademicYear;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\LiveKit\LiveKitRoomManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MeetingParticipantRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_assigned_host_removes_participant_after_commit_and_payload_is_safe(): void
    {
        Event::fake([MeetingParticipantRemoved::class]);
        [$class, $meeting, $host, $participant] = $this->scenario();
        $participant->update(['join_reserved_until' => now()->addMinutes(5)]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('removeParticipant')->once()->with($meeting->livekit_room_name, $participant->livekit_identity)->andReturn(MeetingProviderState::Ended);
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($host)->deleteJson(route('meetings.participants.destroy', [$class, $meeting, $participant]), ['reason' => '<b>Class safety</b>'])->assertOk()->assertJsonMissingPath('participant.livekit_identity');
        $participant->refresh();
        $this->assertNotNull($participant->removed_at);
        $this->assertNull($participant->join_reserved_until);
        $this->assertSame('Class safety', $participant->removal_reason);
        Event::assertDispatched(MeetingParticipantRemoved::class);
    }

    public function test_student_unrelated_teacher_and_host_participant_are_denied(): void
    {
        [$class, $meeting, $host, $participant] = $this->scenario();
        $student = $participant->user;
        $unrelated = $this->roleUser('Teacher');
        $hostParticipant = MeetingParticipant::factory()->host()->create(['meeting_id' => $meeting->id, 'user_id' => $host->id]);
        $this->actingAs($student)->deleteJson(route('meetings.participants.destroy', [$class, $meeting, $participant]))->assertForbidden();
        $this->actingAs($unrelated)->deleteJson(route('meetings.participants.destroy', [$class, $meeting, $participant]))->assertForbidden();
        $this->actingAs($host)->deleteJson(route('meetings.participants.destroy', [$class, $meeting, $hostParticipant]))->assertForbidden();
    }

    public function test_admin_and_super_admin_can_remove_and_repeated_removal_is_canonical(): void
    {
        foreach (['Admin', 'Super Admin'] as $role) {
            [$class, $meeting, , $participant] = $this->scenario();
            $actor = $this->roleUser($role);
            $rooms = Mockery::mock(LiveKitRoomManager::class);
            $rooms->shouldReceive('removeParticipant')->twice()->andReturn(MeetingProviderState::Ended);
            $this->app->instance(LiveKitRoomManager::class, $rooms);
            $url = route('meetings.participants.destroy', [$class, $meeting, $participant]);
            $this->actingAs($actor)->deleteJson($url)->assertOk();
            $this->actingAs($actor)->deleteJson($url)->assertOk();
            $class->academicYear()->update(['status' => 'closed', 'active_slot' => null]);
        }
    }

    public function test_provider_failure_keeps_authoritative_removal_and_blocks_future_token(): void
    {
        [$class, $meeting, $host, $participant] = $this->scenario();
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('removeParticipant')->once()->andReturn(MeetingProviderState::Unknown);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
        $this->actingAs($host)->deleteJson(route('meetings.participants.destroy', [$class, $meeting, $participant]))->assertOk();
        $this->assertNotNull($participant->fresh()->removed_at);
        $this->actingAs($participant->user)->postJson(route('meetings.token', [$class, $meeting]))->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.participant-removal-provider-pending']);
    }

    public function test_participant_list_exposes_only_safe_public_fields(): void
    {
        [$class, $meeting, $host, $participant] = $this->scenario();
        $participant->user->update(['avatar_path' => "user-avatars/{$participant->user_id}/profile.jpg"]);
        $response = $this->actingAs($host)->getJson(route('meetings.participants.index', [$class, $meeting]))->assertOk();
        $response->assertJsonPath('participants.0.reference', $participant->public_uuid)
            ->assertJsonPath('participants.0.avatar_url', Storage::disk('public')->url("user-avatars/{$participant->user_id}/profile.jpg"))
            ->assertJsonMissingPath('participants.0.user_id')
            ->assertJsonMissingPath('participants.0.avatar_path')
            ->assertJsonMissingPath('participants.0.livekit_identity')
            ->assertJsonMissingPath('participants.0.participant_sid')
            ->assertJsonMissingPath('participants.0.email');
    }

    private function scenario(): array
    {
        $class = SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active]);
        $host = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $host->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id]);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);
        $student = $this->roleUser('Student');
        $participant = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $student->id]);

        return [$class, $meeting, $host, $participant];
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
