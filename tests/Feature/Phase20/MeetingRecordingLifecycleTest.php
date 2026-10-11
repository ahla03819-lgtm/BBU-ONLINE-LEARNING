<?php

namespace Tests\Feature\Phase20;

use App\Actions\Recordings\FinalizeMeetingRecording;
use App\Actions\Recordings\ReconcileMeetingRecordingState;
use App\Actions\Recordings\StopMeetingRecording;
use App\Enums\MeetingRecordingProviderStatus;
use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingRecordingStopReason;
use App\Enums\MeetingStatus;
use App\Events\MeetingRecordingChanged;
use App\Jobs\FinalizeMeetingRecordingOutput;
use App\Jobs\StopMeetingRecordingAtDeadline;
use App\Models\MeetingRecording;
use App\Models\Message;
use App\Support\MessagePayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

class MeetingRecordingLifecycleTest extends RecordingTestCase
{
    public function test_an_assigned_host_starts_a_server_side_recording_that_the_provider_confirms(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();

        $recording = $this->startRecording($teacher, $meeting, 12);

        $this->assertSame(MeetingRecordingStatus::Recording, $recording->status);
        $this->assertSame($teacher->id, $recording->started_by_user_id);
        $this->assertSame('EG_test_egress', $recording->provider_egress_id);
        $this->assertSame('screen-share', $recording->layout);
        $this->assertCount(1, $this->recordings->starts);
        // The provider is asked to capture the server-derived room name, never
        // anything a browser supplied.
        $this->assertSame($meeting->livekit_room_name, $this->recordings->starts[0]['room']);
    }

    public function test_the_provider_output_path_is_stored_before_the_provider_is_told_about_it(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();

        $recording = $this->startRecording($teacher, $meeting, 12);

        // The path the provider was given is recorded on the row, so collection can
        // find the file later without trusting anything reported back.
        $this->assertSame($this->recordings->starts[0]['path'], $recording->provider_output_path);
        $this->assertStringContainsString($recording->public_uuid, (string) $recording->provider_output_path);
        $this->assertStringEndsWith('.mp4', (string) $recording->provider_output_path);
    }

    public function test_two_recordings_can_never_be_told_to_write_the_same_path(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $first = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($first, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($first);
        app(FinalizeMeetingRecording::class)->handle($first);

        $second = $this->startRecording($teacher, $meeting, 5);

        $this->assertNotSame($first->provider_output_path, $second->provider_output_path);
    }

    public function test_the_scheduled_stop_is_computed_from_server_time_not_from_a_client_clock(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();

        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        $recording = $this->startRecording($teacher, $meeting, 12);

        $this->assertTrue($recording->started_at->equalTo(now()));
        $this->assertTrue($recording->scheduled_stop_at->equalTo(now()->addMinutes(12)));
        $this->travelBack();
    }

    public function test_an_open_ended_recording_has_no_deadline_and_no_deadline_job(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();

        $recording = $this->startRecording($teacher, $meeting, null);

        $this->assertNull($recording->scheduled_stop_at);
        Queue::assertNotPushed(StopMeetingRecordingAtDeadline::class);
    }

    public function test_a_bounded_duration_dispatches_the_authoritative_deadline_job(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();

        $recording = $this->startRecording($teacher, $meeting, 12);

        Queue::assertPushed(StopMeetingRecordingAtDeadline::class, fn ($job) => $job->recordingId === $recording->id);
    }

    public function test_a_second_start_cannot_create_a_duplicate_active_recording(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->startRecording($teacher, $meeting, 12);

        $this->expectException(ValidationException::class);
        $this->startRecording($teacher, $meeting, 5);
    }

    public function test_the_database_alone_refuses_a_second_active_recording_for_a_meeting(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->startRecording($teacher, $meeting, 12);

        $this->expectException(UniqueConstraintViolationException::class);
        MeetingRecording::query()->create([
            'meeting_id' => $meeting->id,
            'started_by_user_id' => $teacher->id,
            'provider' => 'livekit',
            'status' => MeetingRecordingStatus::Recording,
            'active_slot' => 1,
        ]);
    }

    public function test_a_finished_recording_releases_the_slot_so_the_meeting_can_be_recorded_again(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $first = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($first, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($first);
        app(FinalizeMeetingRecording::class)->handle($first);

        $this->assertSame(MeetingRecordingStatus::Ready, $first->fresh()->status);
        $this->assertNull($first->fresh()->active_slot);

        $second = $this->startRecording($teacher, $meeting, 5);
        $this->assertSame(MeetingRecordingStatus::Recording, $second->status);
        $this->assertSame(2, MeetingRecording::query()->where('meeting_id', $meeting->id)->count());
    }

    public function test_the_endpoint_rejects_a_duration_outside_the_configured_bounds(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->postJson(route('meetings.recordings.store', [$class, $meeting->uuid]), ['duration_minutes' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('duration_minutes');

        $this->actingAs($teacher)
            ->postJson(route('meetings.recordings.store', [$class, $meeting->uuid]), ['duration_minutes' => 9999])
            ->assertStatus(422)->assertJsonValidationErrors('duration_minutes');

        $this->actingAs($teacher)
            ->postJson(route('meetings.recordings.store', [$class, $meeting->uuid]), ['duration_minutes' => 'twelve'])
            ->assertStatus(422)->assertJsonValidationErrors('duration_minutes');

        $this->assertCount(0, $this->recordings->starts);
    }

    public function test_a_manual_stop_asks_the_provider_once_and_records_the_reason(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        $response = $this->actingAs($teacher)
            ->postJson(route('meetings.recordings.stop', [$class, $meeting->uuid]))
            ->assertOk();

        $stopped = $recording->fresh();
        $this->assertSame(MeetingRecordingStatus::Processing, $stopped->status);
        $this->assertSame(MeetingRecordingStopReason::Manual, $stopped->stop_reason);
        $this->assertSame($teacher->id, $stopped->stopped_by_user_id);
        $this->assertSame(['EG_test_egress'], $this->recordings->stops);
        $this->assertSame('processing', $response->json('recording.status'));
    }

    public function test_stopping_with_no_active_recording_is_a_answered_no_op(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->postJson(route('meetings.recordings.stop', [$class, $meeting->uuid]))
            ->assertOk()
            ->assertExactJson(['recording' => null, 'message' => 'This meeting is not being recorded.']);

        $this->assertCount(0, $this->recordings->stops);
    }

    public function test_the_deadline_job_stops_a_recording_with_the_duration_reason(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        // Nothing is stopped before the deadline actually arrives.
        (new StopMeetingRecordingAtDeadline($recording->id))->handle(app(StopMeetingRecording::class));
        $this->assertSame(MeetingRecordingStatus::Recording, $recording->fresh()->status);
        $this->assertCount(0, $this->recordings->stops);

        $this->travel(13)->minutes();
        (new StopMeetingRecordingAtDeadline($recording->id))->handle(app(StopMeetingRecording::class));

        $stopped = $recording->fresh();
        $this->assertSame(MeetingRecordingStatus::Processing, $stopped->status);
        $this->assertSame(MeetingRecordingStopReason::DurationReached, $stopped->stop_reason);
        $this->assertCount(1, $this->recordings->stops);
        $this->travelBack();
    }

    public function test_the_deadline_job_firing_twice_asks_the_provider_once(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->travel(13)->minutes();

        $job = new StopMeetingRecordingAtDeadline($recording->id);
        $job->handle(app(StopMeetingRecording::class));
        $job->handle(app(StopMeetingRecording::class));
        (new StopMeetingRecordingAtDeadline($recording->id))->handle(app(StopMeetingRecording::class));

        $this->assertCount(1, $this->recordings->stops);
        $this->travelBack();
    }

    public function test_the_reconciliation_sweep_stops_a_recording_whose_deadline_passed(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->travel(20)->minutes();

        $result = app(ReconcileMeetingRecordingState::class)->handle();

        $this->assertSame(1, $result['stopped']);
        $this->assertSame(MeetingRecordingStopReason::DurationReached, $recording->fresh()->stop_reason);
        $this->assertCount(1, $this->recordings->stops);
        $this->travelBack();
    }

    public function test_the_reconciliation_sweep_is_idempotent(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->travel(20)->minutes();

        app(ReconcileMeetingRecordingState::class)->handle();
        app(ReconcileMeetingRecordingState::class)->handle();
        app(ReconcileMeetingRecordingState::class)->handle();

        $this->assertCount(1, $this->recordings->stops);
        $this->travelBack();
    }

    public function test_a_provider_that_cannot_be_reached_preserves_the_stopping_state_for_reconciliation(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->recordings->unreachable = true;

        $stopped = app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);

        $this->assertSame(MeetingRecordingStatus::Stopping, $stopped->status);
        $this->assertSame(1, $stopped->active_slot);
        $this->assertNull($stopped->failure_reason);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
    }

    public function test_provider_duration_seconds_are_stored_without_numeric_overflow(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->recordings->file(64, 4_200_000);

        $stopped = app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);

        $this->assertSame(MeetingRecordingStatus::Processing, $stopped->status);
        $this->assertSame(64, $stopped->duration_seconds);
        $this->assertDatabaseHas('meeting_recordings', ['id' => $recording->id, 'duration_seconds' => 64]);
    }

    public function test_reconciliation_recovers_an_uncertain_stop_after_the_provider_reports_complete(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->recordings->stopStatus = MeetingRecordingProviderStatus::Unknown;

        $stopping = app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->assertSame(MeetingRecordingStatus::Stopping, $stopping->status);

        $this->recordings->inspectStatus = MeetingRecordingProviderStatus::Complete;
        $this->recordings->ready();
        $this->publishProviderOutput($recording);
        [$ready] = app(FinalizeMeetingRecording::class)->handle($stopping);

        $this->assertSame(MeetingRecordingStatus::Ready, $ready->status);
        $this->assertSame(720, $ready->duration_seconds);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
    }

    public function test_an_unconfirmed_stop_fails_after_the_bounded_reconciliation_deadline(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->recordings->stopStatus = MeetingRecordingProviderStatus::Unknown;
        $stopping = app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $stopping->forceFill([
            'stopped_at' => now()->subMinutes((int) config('meeting-recordings.collection_deadline_minutes') + 1),
        ])->save();
        $this->recordings->unreachable = true;

        [$failed, $stillSettling] = app(FinalizeMeetingRecording::class)->handle($stopping);

        $this->assertFalse($stillSettling);
        $this->assertSame(MeetingRecordingStatus::Failed, $failed->status);
        $this->assertNull($failed->active_slot);
        $this->assertSame('The recording provider did not confirm that the capture stopped.', $failed->failure_reason);
        $this->assertNull(app(MessagePayload::class)
            ->make(Message::query()->where('meeting_recording_id', $recording->id)->firstOrFail())['recording']['playback_url']);
    }

    public function test_a_provider_that_refuses_to_start_fails_the_attempt_and_frees_the_slot(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->recordings->startStatus = MeetingRecordingProviderStatus::Failed;

        $recording = $this->startRecording($teacher, $meeting, 12);

        $this->assertSame(MeetingRecordingStatus::Failed, $recording->status);
        $this->assertNull($recording->active_slot);
    }

    public function test_a_provider_that_never_confirms_the_start_fails_the_attempt(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->recordings->unreachable = true;

        $recording = $this->startRecording($teacher, $meeting, 12);

        $this->assertSame(MeetingRecordingStatus::Failed, $recording->status);
        $this->assertNotSame('', (string) $recording->failure_reason);
    }

    public function test_every_recording_state_change_is_broadcast_to_the_class(): void
    {
        Event::fake([MeetingRecordingChanged::class]);
        [$class, $meeting, $teacher] = $this->scenario();

        $this->actingAs($teacher)->postJson(route('meetings.recordings.store', [$class, $meeting->uuid]), ['duration_minutes' => 12])->assertCreated();
        Event::assertDispatched(MeetingRecordingChanged::class);

        $this->actingAs($teacher)->postJson(route('meetings.recordings.stop', [$class, $meeting->uuid]))->assertOk();
        Event::assertDispatchedTimes(MeetingRecordingChanged::class, 2);
    }

    public function test_the_realtime_recording_payload_does_not_broadcast_viewer_permissions(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        $projection = (new MeetingRecordingChanged($recording))->broadcastWith()['recording'];

        $this->assertSame($meeting->uuid, (new MeetingRecordingChanged($recording))->broadcastWith()['meeting_uuid']);
        $this->assertArrayNotHasKey('can_start', $projection);
        $this->assertArrayNotHasKey('can_stop', $projection);
    }

    public function test_the_status_endpoint_hands_back_the_authoritative_deadline_and_server_clock(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        $response = $this->actingAs($teacher)
            ->getJson(route('meetings.recordings.current', [$class, $meeting->uuid]))
            ->assertOk();

        $this->assertSame($recording->public_uuid, $response->json('recording.reference'));
        $this->assertSame('recording', $response->json('recording.status'));
        $this->assertSame($recording->scheduled_stop_at->toIso8601String(), $response->json('recording.scheduled_stop_at'));
        $this->assertNotNull($response->json('server_now_at'));
        // A recording that is not ready must not carry a playback URL at all.
        $this->assertNull($response->json('recording.playback_url'));
    }

    public function test_a_recording_never_reports_a_disk_or_a_provider_path_to_a_client(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->startRecording($teacher, $meeting, 12);

        $payload = $this->actingAs($teacher)
            ->getJson(route('meetings.recordings.current', [$class, $meeting->uuid]))
            ->assertOk()
            ->json('recording');

        foreach (['storage_disk', 'storage_path', 'provider_output_path', 'provider_egress_id', 'public_uuid'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
        }
    }

    public function test_the_reconcile_command_runs_the_sweep(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(9, 0, 0));
        [$class, $meeting, $teacher] = $this->scenario();
        $this->startRecording($teacher, $meeting, 12);
        $this->travel(20)->minutes();

        $this->artisan('meetings:reconcile-recordings')->assertSuccessful();

        $this->assertCount(1, $this->recordings->stops);
        $this->travelBack();
    }

    public function test_a_recording_cannot_be_started_for_a_meeting_that_is_not_active(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $meeting->update(['status' => MeetingStatus::Ended]);

        $this->expectException(AuthorizationException::class);
        $this->startRecording($teacher, $meeting->fresh(), 12);
    }

    public function test_the_collection_job_is_dispatched_once_the_capture_has_stopped(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);

        Queue::assertPushed(FinalizeMeetingRecordingOutput::class, fn ($job) => $job->recordingId === $recording->id);
    }
}
