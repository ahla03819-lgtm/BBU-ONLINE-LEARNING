<?php

namespace Tests\Feature\Phase7;

use App\Enums\MeetingMicrophoneMuteResult;
use App\Enums\SchoolClassStatus;
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
use Mockery;
use Tests\TestCase;

class MeetingParticipantMicrophoneModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_host_can_mute_another_connected_participant_without_exposing_provider_identity(): void
    {
        [$class, $meeting, $host, $participant] = $this->scenario();
        $this->expectMute($meeting, $participant, MeetingMicrophoneMuteResult::Muted);

        $this->actingAs($host)
            ->getJson(route('meetings.participants.index', [$class, $meeting]))
            ->assertOk()
            ->assertJsonPath('participants.0.can_mute', true)
            ->assertJsonMissingPath('participants.0.livekit_identity');

        $this->actingAs($host)
            ->patchJson(route('meetings.participants.mute', [$class, $meeting, $participant]))
            ->assertOk()
            ->assertJsonPath('result', 'muted')
            ->assertJsonPath('participant.reference', $participant->public_uuid)
            ->assertJsonMissingPath('participant.livekit_identity')
            ->assertJsonMissingPath('track_sid');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'meeting.participant-muted',
            'target_id' => $participant->id,
        ]);
    }

    public function test_authorized_administrator_can_mute_when_policy_allows(): void
    {
        [$class, $meeting, , $participant] = $this->scenario();
        $moderator = $this->roleUser('Admin');
        $this->expectMute($meeting, $participant, MeetingMicrophoneMuteResult::Muted);

        $this->actingAs($moderator)
            ->patchJson(route('meetings.participants.mute', [$class, $meeting, $participant]))
            ->assertOk()
            ->assertJsonPath('result', 'muted');
    }

    public function test_student_cannot_mute_another_participant(): void
    {
        [$class, $meeting, , $participant] = $this->scenario();
        $other = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $this->roleUser('Student')->id]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldNotReceive('muteParticipantMicrophone');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($participant->user)
            ->patchJson(route('meetings.participants.mute', [$class, $meeting, $other]))
            ->assertForbidden();
    }

    public function test_moderator_cannot_target_an_unrelated_meeting_participant(): void
    {
        [$class, $meeting, $host] = $this->scenario();
        $otherMeeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);
        $unrelated = MeetingParticipant::factory()->create(['meeting_id' => $otherMeeting->id]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldNotReceive('muteParticipantMicrophone');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($host)
            ->patchJson(route('meetings.participants.mute', [$class, $meeting, $unrelated]))
            ->assertNotFound();
    }

    public function test_participant_without_an_active_microphone_is_handled_safely(): void
    {
        [$class, $meeting, $host, $participant] = $this->scenario();
        $this->expectMute($meeting, $participant, MeetingMicrophoneMuteResult::NoActiveMicrophone);

        $this->actingAs($host)
            ->patchJson(route('meetings.participants.mute', [$class, $meeting, $participant]))
            ->assertOk()
            ->assertJsonPath('result', 'no_active_microphone');
    }

    public function test_absent_participant_and_provider_failure_return_safe_errors(): void
    {
        foreach ([
            [MeetingMicrophoneMuteResult::ParticipantNotPresent, 409, 'participant_not_present'],
            [MeetingMicrophoneMuteResult::ProviderFailure, 502, 'provider_failure'],
        ] as [$providerResult, $status, $jsonResult]) {
            [$class, $meeting, $host, $participant] = $this->scenario();
            $this->expectMute($meeting, $participant, $providerResult);

            $this->actingAs($host)
                ->patchJson(route('meetings.participants.mute', [$class, $meeting, $participant]))
                ->assertStatus($status)
                ->assertJsonPath('result', $jsonResult)
                ->assertJsonMissingPath('livekit_identity')
                ->assertJsonMissingPath('track_sid');

            $class->academicYear()->update(['status' => 'closed', 'active_slot' => null]);
        }
    }

    public function test_moderator_cannot_mute_self_or_the_assigned_host_and_no_unmute_endpoint_exists(): void
    {
        [$class, $meeting, $host] = $this->scenario();
        $hostParticipant = MeetingParticipant::factory()->host()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $host->id,
        ]);
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldNotReceive('muteParticipantMicrophone');
        $this->app->instance(LiveKitRoomManager::class, $rooms);

        $this->actingAs($host)
            ->patchJson(route('meetings.participants.mute', [$class, $meeting, $hostParticipant]))
            ->assertForbidden();

        $this->actingAs($host)
            ->patchJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/participants/{$hostParticipant->public_uuid}/unmute")
            ->assertNotFound();
    }

    private function expectMute(Meeting $meeting, MeetingParticipant $participant, MeetingMicrophoneMuteResult $result): void
    {
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('muteParticipantMicrophone')
            ->once()
            ->with($meeting->livekit_room_name, $participant->livekit_identity)
            ->andReturn($result);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
    }

    private function scenario(): array
    {
        $class = SchoolClass::factory()->create([
            'academic_year_id' => AcademicYear::factory()->active(),
            'status' => SchoolClassStatus::Active,
        ]);
        $host = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $host->id]);
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'school_class_id' => $class->id,
        ]);
        $meeting = Meeting::factory()->active()->create([
            'school_class_id' => $class->id,
            'host_user_id' => $host->id,
        ]);
        $participant = MeetingParticipant::factory()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $this->roleUser('Student')->id,
        ]);

        return [$class, $meeting, $host, $participant];
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
