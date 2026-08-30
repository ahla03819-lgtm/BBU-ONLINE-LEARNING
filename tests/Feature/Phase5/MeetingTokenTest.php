<?php

namespace Tests\Feature\Phase5;

use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingJoinRequest;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLiveKitTokenIssuer;
use Tests\TestCase;

class MeetingTokenTest extends TestCase
{
    use RefreshDatabase;

    private FakeLiveKitTokenIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->issuer = new FakeLiveKitTokenIssuer;
        $this->app->instance(LiveKitTokenIssuer::class, $this->issuer);
        config([
            'livekit.url' => 'wss://public.example.test',
            'livekit.api_url' => 'https://provider.example.test',
            'livekit.api_key' => 'server-key',
            'livekit.api_secret' => 'server-secret',
        ]);
    }

    public function test_current_student_receives_safe_room_token_response_and_durable_participant(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'lifecycle_version' => 7]);
        $this->admit($meeting, $student);

        $response = $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]));

        $response->assertOk()->assertExactJsonStructure([
            'token', 'server_url', 'expires_at', 'lifecycle_version',
            'participant' => ['id', 'display_name', 'role'],
        ])->assertJsonPath('token', 'safe-test-token')
            ->assertJsonPath('server_url', 'wss://public.example.test')
            ->assertJsonPath('lifecycle_version', 7)
            ->assertJsonMissingPath('api_url')
            ->assertJsonMissingPath('api_key')
            ->assertJsonMissingPath('api_secret')
            ->assertJsonMissingPath('participant.livekit_identity');
        $this->assertDatabaseHas('meeting_participants', ['meeting_id' => $meeting->id, 'user_id' => $student->id]);
        $this->assertDatabaseCount('meeting_attendance_sessions', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.token-issued']);
    }

    public function test_repeated_issuance_reuses_one_participant_and_one_capacity_slot(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'max_participants' => 2]);
        $this->admit($meeting, $student);

        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();

        $this->assertSame(2, $this->issuer->calls);
        $this->assertSame(1, MeetingParticipant::query()->where('meeting_id', $meeting->id)->count());
    }

    public function test_capacity_counts_other_unexpired_reservations_and_denies_without_fake_attendance(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'max_participants' => 2]);
        $this->admit($meeting, $student);
        MeetingParticipant::factory()->count(2)->create(['meeting_id' => $meeting->id, 'join_reserved_until' => now()->addMinute()]);

        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))
            ->assertUnprocessable()->assertJsonValidationErrors('meeting');
        $this->assertDatabaseMissing('meeting_participants', ['meeting_id' => $meeting->id, 'user_id' => $student->id]);
        $this->assertDatabaseCount('meeting_attendance_sessions', 0);
    }

    public function test_historical_student_inactive_meeting_and_cross_class_route_are_denied(): void
    {
        $class = $this->activeClass();
        $historical = $this->student($class, false);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        $this->actingAs($historical)->postJson(route('meetings.token', [$class, $meeting]))->assertForbidden();

        $current = $this->student($class);
        $meeting->update(['status' => MeetingStatus::Ended]);
        $this->actingAs($current)->postJson(route('meetings.token', [$class, $meeting]))->assertForbidden();

        $other = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $meeting->update(['status' => MeetingStatus::Active]);
        $this->actingAs($current)->postJson(route('meetings.token', [$other, $meeting]))->assertNotFound();
    }

    public function test_token_endpoint_is_rate_limited_per_user_and_meeting(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        $this->admit($meeting, $student);
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertOk();
        }
        $this->actingAs($student)->postJson(route('meetings.token', [$class, $meeting]))->assertTooManyRequests();
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active]);
    }

    private function student(SchoolClass $class, bool $current = true): User
    {
        $user = User::factory()->create();
        $user->assignRole('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id, 'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id, 'current_slot' => $current ? 1 : null,
            'ended_on' => $current ? null : now()->toDateString(),
        ]);

        return $user;
    }

    private function admit(Meeting $meeting, User $student): void
    {
        MeetingJoinRequest::factory()->admitted()->create(['meeting_id' => $meeting->id, 'requester_user_id' => $student->id]);
    }
}
