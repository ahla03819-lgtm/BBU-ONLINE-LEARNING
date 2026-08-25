<?php

namespace Tests\Feature\Phase5;

use App\Enums\LiveKitWebhookStatus;
use App\Enums\MeetingJoinPolicy;
use App\Enums\MeetingParticipantRole;
use App\Enums\MeetingStatus;
use App\Models\ClassSubject;
use App\Models\LiveKitWebhookEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_foundation_factories_persist_enum_casts_and_opaque_identifiers(): void
    {
        $meeting = Meeting::factory()->create();
        $participant = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id]);
        $event = LiveKitWebhookEvent::factory()->create();

        $this->assertInstanceOf(MeetingStatus::class, $meeting->status);
        $this->assertSame(MeetingStatus::Scheduled, $meeting->status);
        $this->assertInstanceOf(MeetingJoinPolicy::class, $meeting->join_policy);
        $this->assertSame(MeetingJoinPolicy::ActiveOnly, $meeting->join_policy);
        $this->assertTrue(Str::isUuid($meeting->uuid));
        $this->assertMatchesRegularExpression('/^edway_[A-Za-z0-9]{40}$/', $meeting->livekit_room_name);

        $this->assertInstanceOf(MeetingParticipantRole::class, $participant->role);
        $this->assertTrue(Str::isUuid($participant->livekit_identity));
        $this->assertInstanceOf(LiveKitWebhookStatus::class, $event->status);
        $this->assertSame(LiveKitWebhookStatus::Pending, $event->status);
    }

    public function test_meeting_relationships_include_class_subject_people_participants_and_attendance(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $classSubject = ClassSubject::factory()->create(['school_class_id' => $schoolClass->id]);
        $creator = User::factory()->create();
        $host = User::factory()->create();
        $attendee = User::factory()->create();

        $meeting = Meeting::factory()->create([
            'school_class_id' => $schoolClass->id,
            'class_subject_id' => $classSubject->id,
            'created_by' => $creator->id,
            'host_user_id' => $host->id,
        ]);
        $participant = MeetingParticipant::factory()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $attendee->id,
        ]);
        $joinEvent = LiveKitWebhookEvent::factory()->create();
        $leaveEvent = LiveKitWebhookEvent::factory()->create();
        $attendance = MeetingAttendanceSession::factory()->create([
            'meeting_participant_id' => $participant->id,
            'join_webhook_event_id' => $joinEvent->event_id,
            'leave_webhook_event_id' => $leaveEvent->event_id,
        ]);

        $this->assertTrue($meeting->schoolClass->is($schoolClass));
        $this->assertTrue($meeting->classSubject->is($classSubject));
        $this->assertTrue($meeting->creator->is($creator));
        $this->assertTrue($meeting->host->is($host));
        $this->assertTrue($meeting->participants->contains($participant));
        $this->assertTrue($creator->createdMeetings->contains($meeting));
        $this->assertTrue($host->hostedMeetings->contains($meeting));
        $this->assertTrue($attendee->meetingParticipations->contains($participant));
        $this->assertTrue($schoolClass->meetings->contains($meeting));
        $this->assertTrue($classSubject->meetings->contains($meeting));
        $this->assertTrue($attendance->meetingParticipant->is($participant));
        $this->assertTrue($attendance->joinWebhookEvent->is($joinEvent));
        $this->assertTrue($attendance->leaveWebhookEvent->is($leaveEvent));
        $this->assertTrue($joinEvent->joinedAttendanceSessions->contains($attendance));
        $this->assertTrue($leaveEvent->leftAttendanceSessions->contains($attendance));
    }

    public function test_webhook_event_stores_only_normalized_operational_metadata(): void
    {
        $event = LiveKitWebhookEvent::factory()->create([
            'event_type' => 'participant_joined',
            'livekit_room_name' => 'edway_normalized-room',
            'participant_identity' => (string) Str::uuid(),
            'status' => LiveKitWebhookStatus::Processed,
            'attempts' => 2,
        ]);

        $this->assertDatabaseHas('livekit_webhook_events', [
            'event_id' => $event->event_id,
            'event_type' => 'participant_joined',
            'livekit_room_name' => 'edway_normalized-room',
            'status' => LiveKitWebhookStatus::Processed->value,
            'attempts' => 2,
        ]);
        $this->assertEqualsCanonicalizing([
            'event_id',
            'event_type',
            'livekit_room_name',
            'participant_identity',
            'participant_sid',
            'occurred_at',
            'payload_sha256',
            'status',
            'attempts',
            'next_attempt_at',
            'processed_at',
            'processing_error',
            'created_at',
            'updated_at',
        ], array_keys($event->getAttributes()));
    }

    public function test_webhook_event_id_is_an_idempotency_constraint(): void
    {
        $event = LiveKitWebhookEvent::factory()->create();

        $this->expectException(QueryException::class);

        LiveKitWebhookEvent::factory()->create(['event_id' => $event->event_id]);
    }

    public function test_meeting_uuid_must_be_unique(): void
    {
        $meeting = Meeting::factory()->create();

        $this->expectException(QueryException::class);

        Meeting::factory()->create(['uuid' => $meeting->uuid]);
    }

    public function test_livekit_room_name_must_be_unique(): void
    {
        $meeting = Meeting::factory()->create();

        $this->expectException(QueryException::class);

        Meeting::factory()->create(['livekit_room_name' => $meeting->livekit_room_name]);
    }

    public function test_participant_is_unique_per_meeting_and_user(): void
    {
        $participant = MeetingParticipant::factory()->create();

        $this->expectException(QueryException::class);

        MeetingParticipant::factory()->create([
            'meeting_id' => $participant->meeting_id,
            'user_id' => $participant->user_id,
        ]);
    }

    public function test_participant_identity_is_unique_per_meeting(): void
    {
        $participant = MeetingParticipant::factory()->create();

        $this->expectException(QueryException::class);

        MeetingParticipant::factory()->create([
            'meeting_id' => $participant->meeting_id,
            'livekit_identity' => $participant->livekit_identity,
        ]);
    }

    public function test_attendance_session_is_unique_per_participant_and_livekit_sid(): void
    {
        $session = MeetingAttendanceSession::factory()->create();

        $this->expectException(QueryException::class);

        MeetingAttendanceSession::factory()->create([
            'meeting_participant_id' => $session->meeting_participant_id,
            'livekit_participant_sid' => $session->livekit_participant_sid,
        ]);
    }

    public function test_join_webhook_event_can_be_consumed_by_only_one_attendance_session(): void
    {
        $session = MeetingAttendanceSession::factory()->create();

        $this->expectException(QueryException::class);

        MeetingAttendanceSession::factory()->create([
            'join_webhook_event_id' => $session->join_webhook_event_id,
        ]);
    }

    public function test_leave_webhook_event_can_be_consumed_by_only_one_attendance_session(): void
    {
        $session = MeetingAttendanceSession::factory()->create([
            'leave_webhook_event_id' => LiveKitWebhookEvent::factory(),
        ]);

        $this->expectException(QueryException::class);

        MeetingAttendanceSession::factory()->create([
            'leave_webhook_event_id' => $session->leave_webhook_event_id,
        ]);
    }

    public function test_meetings_restrict_deletion_of_their_class_and_optional_subject(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $classSubject = ClassSubject::factory()->create(['school_class_id' => $schoolClass->id]);
        Meeting::factory()->create([
            'school_class_id' => $schoolClass->id,
            'class_subject_id' => $classSubject->id,
        ]);

        try {
            $classSubject->delete();
            $this->fail('A referenced class subject was deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('class_subjects', ['id' => $classSubject->id]);
        }

        $this->expectException(QueryException::class);
        $schoolClass->delete();
    }

    public function test_user_foreign_keys_are_nullified_to_preserve_meeting_history(): void
    {
        $user = User::factory()->create();
        $meeting = Meeting::factory()->create([
            'created_by' => $user->id,
            'host_user_id' => $user->id,
        ]);
        $participant = MeetingParticipant::factory()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'removed_by' => $user->id,
        ]);

        $user->delete();

        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'created_by' => null,
            'host_user_id' => null,
        ]);
        $this->assertDatabaseHas('meeting_participants', [
            'id' => $participant->id,
            'user_id' => null,
            'removed_by' => null,
        ]);
    }

    public function test_attendance_history_restricts_participant_and_webhook_deletion(): void
    {
        $session = MeetingAttendanceSession::factory()->create();
        $participant = $session->meetingParticipant;
        $joinEvent = $session->joinWebhookEvent;

        try {
            $participant->delete();
            $this->fail('A participant with attendance history was deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('meeting_participants', ['id' => $participant->id]);
        }

        $this->expectException(QueryException::class);
        $joinEvent->delete();
    }
}
