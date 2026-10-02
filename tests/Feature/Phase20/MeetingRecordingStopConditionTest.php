<?php

namespace Tests\Feature\Phase20;

use App\Actions\Meetings\EndMeeting;
use App\Actions\Meetings\RecordMeetingLeave;
use App\Actions\Recordings\ReconcileMeetingRecordingState;
use App\Actions\Recordings\StopMeetingRecording;
use App\Contracts\MeetingLifecycleProvider;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingRecordingStopReason;
use App\Enums\MeetingStatus;
use App\Jobs\StopMeetingRecordingAtDeadline;
use App\Models\LiveKitWebhookEvent;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
use App\Models\MeetingRecording;
use App\Models\Message;
use App\Services\LiveKit\LiveKitRoomManager;

/**
 * The lifecycle guarantees that only show up when two triggers collide, plus the
 * explicit-leave rule the project depends on.
 *
 * The race tests do not rely on timing: they drive the competing stop paths against
 * one recording and then assert the single property that matters, which is that the
 * provider was asked to stop exactly once and the class got exactly one card.
 */
class MeetingRecordingStopConditionTest extends RecordingTestCase
{
    public function test_an_explicit_recorder_leave_stops_the_recording_with_the_leave_reason(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        $this->actingAs($teacher)
            ->postJson(route('meetings.leave', [$class, $meeting->uuid]))
            ->assertOk();

        $left = $recording->fresh();
        $this->assertSame(MeetingRecordingStopReason::RecorderLeft, $left->stop_reason);
        $this->assertSame(MeetingRecordingStatus::Processing, $left->status);
        $this->assertCount(1, $this->recordings->stops);
    }

    public function test_a_participant_left_webhook_never_stops_a_recording(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $participant = MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('user_id', $student->id)->firstOrFail();
        MeetingAttendanceSession::factory()->create([
            'meeting_participant_id' => $participant->id,
            'livekit_participant_sid' => 'PA_refresh_cycle',
            'left_at' => null,
        ]);

        $this->deliverWebhook('participant_left', $meeting->livekit_room_name, (string) $participant->livekit_identity, 'PA_refresh_cycle');

        // A refresh and a reconnect look exactly like this on the provider, so the
        // capture must be untouched.
        $this->assertSame(MeetingRecordingStatus::Recording, $recording->fresh()->status);
        $this->assertCount(0, $this->recordings->stops);
        $this->assertSame(0, Message::query()->where('meeting_recording_id', $recording->id)->count());
    }

    public function test_a_different_teacher_leaving_does_not_stop_the_active_recording(): void
    {
        [$class, $meeting, $teacher, , $outsiderTeacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        app(RecordMeetingLeave::class)->handle($outsiderTeacher, $meeting);

        $this->assertSame(MeetingRecordingStatus::Recording, $recording->fresh()->status);
        $this->assertCount(0, $this->recordings->stops);
    }

    public function test_a_student_leaving_does_not_stop_the_active_recording(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        $this->actingAs($student)->postJson(route('meetings.leave', [$class, $meeting->uuid]))->assertOk();

        $this->assertSame(MeetingRecordingStatus::Recording, $recording->fresh()->status);
        $this->assertCount(0, $this->recordings->stops);
    }

    public function test_the_leave_signal_never_interferes_with_the_meeting_itself(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->startRecording($teacher, $meeting, 12);

        $this->actingAs($teacher)
            ->postJson(route('meetings.leave', [$class, $meeting->uuid]))
            ->assertOk()
            ->assertJsonPath('left', true);

        $this->assertSame(MeetingStatus::Active, $meeting->fresh()->status);
        $this->assertNull($meeting->fresh()->actual_end_at);
        $this->assertSame(1, MeetingParticipant::query()->where('meeting_id', $meeting->id)->count());
    }

    public function test_ending_the_meeting_stops_the_recording_once_and_the_meeting_still_ends(): void
    {
        $this->mockLifecycleProvider();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        $this->actingAs($teacher)
            ->postJson(route('meetings.end', [$class, $meeting->uuid]))
            ->assertOk();

        $this->assertSame(MeetingStatus::Ended, $meeting->fresh()->status);
        $this->assertNotNull($meeting->fresh()->actual_end_at);
        $this->assertSame(MeetingRecordingStopReason::MeetingEnded, $recording->fresh()->stop_reason);
        $this->assertCount(1, $this->recordings->stops);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
    }

    public function test_ending_the_meeting_when_no_recording_is_running_is_unchanged(): void
    {
        $this->mockLifecycleProvider();
        [$class, $meeting, $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->postJson(route('meetings.end', [$class, $meeting->uuid]))
            ->assertOk();

        $this->assertSame(MeetingStatus::Ended, $meeting->fresh()->status);
        $this->assertCount(0, $this->recordings->stops);
        $this->assertSame(0, MeetingRecording::query()->count());
    }

    public function test_ending_the_meeting_completes_even_when_the_recording_provider_cannot_stop(): void
    {
        $this->mockLifecycleProvider();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->recordings->unreachable = true;

        $this->actingAs($teacher)
            ->postJson(route('meetings.end', [$class, $meeting->uuid]))
            ->assertOk();

        // The recording records its own failure; the meeting is not held hostage.
        $this->assertSame(MeetingStatus::Ended, $meeting->fresh()->status);
        $this->assertSame(MeetingRecordingStatus::Failed, $recording->fresh()->status);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
    }

    public function test_a_room_that_finished_on_its_own_still_produces_a_recording_card(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        $this->deliverWebhook('room_finished', $meeting->livekit_room_name);

        $this->assertSame(MeetingRecordingStopReason::MeetingEnded, $recording->fresh()->stop_reason);
        $this->assertCount(1, $this->recordings->stops);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
    }

    public function test_a_timer_deadline_racing_a_manual_stop_asks_the_provider_once(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->travel(12)->minutes();

        // Whichever path runs first wins the claim; the other must be a no-op.
        (new StopMeetingRecordingAtDeadline($recording->id))->handle(app(StopMeetingRecording::class));
        app(StopMeetingRecording::class)->handle($recording->fresh(), MeetingRecordingStopReason::Manual, $teacher);

        $this->assertCount(1, $this->recordings->stops);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
        $this->assertSame(MeetingRecordingStatus::Processing, $recording->fresh()->status);
        $this->travelBack();
    }

    public function test_a_manual_stop_racing_a_timer_deadline_asks_the_provider_once(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->travel(12)->minutes();

        app(StopMeetingRecording::class)->handle($recording->fresh(), MeetingRecordingStopReason::Manual, $teacher);
        (new StopMeetingRecordingAtDeadline($recording->id))->handle(app(StopMeetingRecording::class));

        $this->assertCount(1, $this->recordings->stops);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
        $this->assertSame(MeetingRecordingStopReason::Manual, $recording->fresh()->stop_reason);
        $this->travelBack();
    }

    public function test_a_timer_deadline_racing_the_end_of_the_meeting_asks_the_provider_once(): void
    {
        $this->mockLifecycleProvider();
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->travel(12)->minutes();

        (new StopMeetingRecordingAtDeadline($recording->id))->handle(app(StopMeetingRecording::class));
        app(EndMeeting::class)->handle($teacher, $meeting);

        $this->assertCount(1, $this->recordings->stops);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
        $this->assertSame(MeetingStatus::Ended, $meeting->fresh()->status);
        $this->travelBack();
    }

    public function test_the_end_of_the_meeting_racing_a_timer_deadline_asks_the_provider_once(): void
    {
        $this->mockLifecycleProvider();
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->travel(12)->minutes();

        app(EndMeeting::class)->handle($teacher, $meeting);
        (new StopMeetingRecordingAtDeadline($recording->id))->handle(app(StopMeetingRecording::class));
        app(ReconcileMeetingRecordingState::class)->handle();

        $this->assertCount(1, $this->recordings->stops);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
        $this->travelBack();
    }

    public function test_a_recorder_leave_racing_the_end_of_the_meeting_asks_the_provider_once(): void
    {
        $this->mockLifecycleProvider();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        app(RecordMeetingLeave::class)->handle($teacher, $meeting);
        app(EndMeeting::class)->handle($teacher, $meeting);

        $this->assertCount(1, $this->recordings->stops);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
        $this->assertSame(MeetingStatus::Ended, $meeting->fresh()->status);
    }

    public function test_every_stop_path_together_still_asks_the_provider_once(): void
    {
        $this->mockLifecycleProvider();
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->travel(12)->minutes();

        (new StopMeetingRecordingAtDeadline($recording->id))->handle(app(StopMeetingRecording::class));
        app(RecordMeetingLeave::class)->handle($teacher, $meeting);
        app(StopMeetingRecording::class)->handle($recording->fresh(), MeetingRecordingStopReason::Manual, $teacher);
        app(EndMeeting::class)->handle($teacher, $meeting);
        app(ReconcileMeetingRecordingState::class)->handle();

        $this->assertCount(1, $this->recordings->stops);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
        $this->travelBack();
    }

    public function test_an_egress_ended_webhook_finalizes_the_recording_without_any_browser(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->recordings->ready();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);

        $this->deliverEgressWebhook('egress_ended', 'EG_test_egress');

        $this->assertSame(MeetingRecordingStatus::Ready, $recording->fresh()->status);
        $this->assertSame('ready', app(\App\Support\MessagePayload::class)
            ->make(Message::query()->where('meeting_recording_id', $recording->id)->firstOrFail())['recording']['status']);
    }

    public function test_an_egress_started_webhook_closes_the_gap_when_the_start_response_never_arrived(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $recording->forceFill(['status' => MeetingRecordingStatus::Starting])->save();

        $this->deliverEgressWebhook('egress_started', 'EG_test_egress');

        $this->assertSame(MeetingRecordingStatus::Recording, $recording->fresh()->status);
    }

    public function test_an_egress_webhook_for_an_unknown_egress_is_ignored(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        $this->deliverEgressWebhook('egress_ended', 'EG_somebody_elses');

        $this->assertSame(MeetingRecordingStatus::Recording, $recording->fresh()->status);
        $this->assertSame(0, Message::query()->where('meeting_recording_id', $recording->id)->count());
    }

    private function mockLifecycleProvider(): void
    {
        $provider = \Mockery::mock(MeetingLifecycleProvider::class);
        $provider->shouldReceive('end')->andReturn(MeetingProviderState::Ended);
        $provider->shouldReceive('start')->andReturn(MeetingProviderState::Active);
        $this->app->instance(MeetingLifecycleProvider::class, $provider);
    }

    private function deliverWebhook(string $type, string $roomName, ?string $identity = null, ?string $sid = null): void
    {
        $event = LiveKitWebhookEvent::query()->create([
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => $type,
            'livekit_room_name' => $roomName,
            'participant_identity' => $identity,
            'participant_sid' => $sid,
            'occurred_at' => now(),
            'payload_sha256' => hash('sha256', $type.$roomName),
            'status' => \App\Enums\LiveKitWebhookStatus::Pending,
            'attempts' => 0,
        ]);

        app(\App\Jobs\ProcessLiveKitWebhook::class, ['eventId' => $event->event_id])->handle(
            $this->app->make(LiveKitRoomManager::class),
            $this->app->make(\App\Services\AuditLogger::class),
            $this->app->make(\App\Actions\Recordings\FinalizeMeetingRecording::class),
        );
    }

    private function deliverEgressWebhook(string $type, string $egressId): void
    {
        $event = LiveKitWebhookEvent::query()->create([
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => $type,
            'egress_id' => $egressId,
            'occurred_at' => now(),
            'payload_sha256' => hash('sha256', $type.$egressId),
            'status' => \App\Enums\LiveKitWebhookStatus::Pending,
            'attempts' => 0,
        ]);

        app(\App\Jobs\ProcessLiveKitWebhook::class, ['eventId' => $event->event_id])->handle(
            $this->app->make(LiveKitRoomManager::class),
            $this->app->make(\App\Services\AuditLogger::class),
            $this->app->make(\App\Actions\Recordings\FinalizeMeetingRecording::class),
        );
    }
}
