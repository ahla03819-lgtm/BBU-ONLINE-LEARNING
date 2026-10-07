<?php

namespace Tests\Feature\PhaseAI;

use App\Actions\Meetings\RequestMeetingAiNotes;
use App\Actions\Meetings\RequestMeetingAiSummary;
use App\Actions\Meetings\StoreMeetingTranscriptSegment;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingAiNote;
use App\Models\MeetingAiSummary;
use App\Models\MeetingTranscript;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MeetingAiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_is_denied_ai_transcript(): void
    {
        $class = $this->activeClass();
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);

        $this->getJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/transcript")->assertStatus(401);
    }

    public function test_unrelated_student_is_denied_ai_transcript(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $student = $this->student($otherClass);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);

        $this->actingAs($student)->getJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/transcript")->assertStatus(403);
    }

    public function test_authorized_student_can_view_transcript(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        MeetingTranscript::factory()->create(['meeting_id' => $meeting->id]);

        $this->actingAs($student)->getJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/transcript")->assertOk()->assertJsonCount(1, 'segments');
    }

    public function test_authorized_teacher_can_view_transcript(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);
        MeetingTranscript::factory()->create(['meeting_id' => $meeting->id]);

        $this->actingAs($teacher)->getJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/transcript")->assertOk()->assertJsonCount(1, 'segments');
    }

    public function test_transcript_is_scoped_to_requested_meeting(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $student = $this->student($class);
        $meetingA = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        $meetingB = Meeting::factory()->active()->create(['school_class_id' => $otherClass->id]);
        MeetingTranscript::factory()->create(['meeting_id' => $meetingA->id]);
        MeetingTranscript::factory()->create(['meeting_id' => $meetingB->id]);

        $this->actingAs($student)->getJson("/collaboration/classes/{$class->id}/meetings/{$meetingA->uuid}/ai/transcript")->assertOk()->assertJsonCount(1, 'segments');
    }

    public function test_authorized_student_can_view_notes_and_summary_shapes(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);

        MeetingAiNote::create([
            'meeting_id' => $meeting->id,
            'language' => 'en',
            'content' => 'Notes',
            'status' => 'ready',
            'provider' => 'null',
            'provider_metadata' => ['language' => 'en', 'segment_count' => 1],
        ]);
        MeetingAiSummary::create([
            'meeting_id' => $meeting->id,
            'language' => 'en',
            'content' => 'Summary',
            'status' => 'ready',
            'provider' => 'null',
            'provider_metadata' => ['language' => 'en', 'segment_count' => 1],
        ]);

        $this->actingAs($student)->getJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/notes")->assertOk()->assertJsonStructure(['note' => ['id', 'language', 'content', 'status', 'provider', 'provider_metadata', 'created_at', 'updated_at']]);
        $this->actingAs($student)->getJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/summary")->assertOk()->assertJsonStructure(['summary' => ['id', 'language', 'content', 'status', 'provider', 'provider_metadata', 'created_at', 'updated_at']]);
    }

    public function test_notes_and_summary_absence_returns_null(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);

        $this->actingAs($student)->getJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/notes")->assertOk()->assertJson(['note' => null]);
        $this->actingAs($student)->getJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/summary")->assertOk()->assertJson(['summary' => null]);
    }

    public function test_unrelated_student_is_denied_ai_notes(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $student = $this->student($otherClass);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);

        $this->actingAs($student)->getJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/notes")->assertStatus(403);
    }

    public function test_unauthorized_student_cannot_request_notes_generation(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $student = $this->student($otherClass);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);

        $this->actingAs($student)->postJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/notes")->assertStatus(403);
    }

    public function test_teacher_can_request_notes_generation(): void
    {
        Queue::fake();

        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);

        $this->actingAs($teacher)->postJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/notes?language=en")->assertOk()->assertJsonStructure(['note' => ['id', 'language', 'status', 'created_at']])->assertJsonPath('note.status', 'generating');
    }

    public function test_unauthorized_student_cannot_request_summary_generation(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $student = $this->student($otherClass);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);

        $this->actingAs($student)->postJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/summary")->assertStatus(403);
    }

    public function test_teacher_can_request_summary_generation(): void
    {
        Queue::fake();

        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);

        $this->actingAs($teacher)->postJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/summary?language=en")->assertOk()->assertJsonStructure(['summary' => ['id', 'language', 'status', 'created_at']])->assertJsonPath('summary.status', 'generating');
    }

    public function test_browser_client_cannot_post_transcript_segments(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);

        // First, create a transcript via internal action (trusted path)
        $internalAction = app(StoreMeetingTranscriptSegment::class);
        $internalAction->handle($student, $meeting, [
            'original_text' => 'Internal transcript',
            'original_language' => 'en',
            'speaker_identity' => 'internal-speaker',
            'speaker_display_name' => 'Internal Speaker',
            'sequence' => 1,
            'started_at' => now(),
            'ended_at' => now()->addMinutes(1),
        ]);

        $initialCount = $meeting->transcripts()->count();
        $this->assertEquals(1, $initialCount);

        // Now attempt to POST via browser endpoint (should not exist)
        $response = $this->actingAs($student)->postJson("/collaboration/classes/{$class->id}/meetings/{$meeting->uuid}/ai/transcript", [
            'original_text' => 'Browser injected transcript',
            'original_language' => 'en',
            'speaker_identity' => 'browser-speaker',
            'speaker_display_name' => 'Browser Speaker',
            'sequence' => 2,
            'started_at' => now()->toIso8601String(),
            'ended_at' => now()->addMinutes(1)->toIso8601String(),
        ]);

        // Route should not allow POST (404, 403, or 405 Method Not Allowed)
        $this->assertContains($response->status(), [403, 404, 405]);

        // Transcript count should not have changed
        $this->assertEquals($initialCount, $meeting->fresh()->transcripts()->count());
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

    private function classTeacher(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'school_class_id' => $class->id,
            'current_slot' => $current ? 1 : null,
            'ends_on' => $current ? null : now()->toDateString(),
        ]);

        return $user;
    }

    private function student(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
            'current_slot' => $current ? 1 : null,
            'ended_on' => $current ? null : now()->toDateString(),
        ]);

        return $user;
    }
}
