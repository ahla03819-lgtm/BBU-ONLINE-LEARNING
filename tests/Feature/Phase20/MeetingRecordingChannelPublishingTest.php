<?php

namespace Tests\Feature\Phase20;

use App\Actions\Recordings\FinalizeMeetingRecording;
use App\Actions\Recordings\StopMeetingRecording;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingRecordingStopReason;
use App\Enums\MessageType;
use App\Jobs\FinalizeMeetingRecordingOutput;
use App\Models\Channel;
use App\Models\ClassSubject;
use App\Models\MeetingRecording;
use App\Models\Message;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class MeetingRecordingChannelPublishingTest extends RecordingTestCase
{
    public function test_stopping_creates_exactly_one_processing_card_in_the_class_channel(): void
    {
        [$class, $meeting, $teacher, , , , $channel] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);

        $cards = Message::query()->where('type', MessageType::MeetingRecording->value)->get();
        $this->assertCount(1, $cards);
        $card = $cards->first();
        $this->assertSame($channel->id, $card->channel_id);
        $this->assertSame($recording->id, $card->meeting_recording_id);
        // A server-authored card has no sender and no client reference, exactly
        // like a system message, so nobody can edit or hide it.
        $this->assertNull($card->sender_id);
        $this->assertNull($card->client_uuid);
    }

    public function test_the_card_is_created_immediately_in_the_processing_state(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);

        $payload = $this->cardPayload($recording);
        $this->assertSame('processing', $payload['recording']['status']);
        $this->assertNull($payload['recording']['playback_url']);
        $this->assertSame('manual', $payload['recording']['stop_reason']);
    }

    public function test_the_ready_state_updates_the_same_card_rather_than_adding_another(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $cardId = Message::query()->where('meeting_recording_id', $recording->id)->value('id');

        $this->publishProviderOutput($recording);
        app(FinalizeMeetingRecording::class)->handle($recording);

        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
        $this->assertSame($cardId, Message::query()->where('meeting_recording_id', $recording->id)->value('id'));

        $payload = $this->cardPayload($recording);
        $this->assertSame('ready', $payload['recording']['status']);
        $this->assertSame(720, $payload['recording']['duration_seconds']);
        $this->assertNotNull($payload['recording']['playback_url']);
    }

    public function test_a_failed_recording_updates_the_same_card_to_a_failed_state(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->recordings->unreachable = true;

        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);

        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
        $payload = $this->cardPayload($recording);
        $this->assertSame('failed', $payload['recording']['status']);
        $this->assertNull($payload['recording']['playback_url']);
    }

    public function test_the_card_reports_the_meeting_the_teacher_and_the_duration(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $meeting->update(['title' => 'Computer Class']);
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);
        app(FinalizeMeetingRecording::class)->handle($recording);

        $payload = $this->cardPayload($recording);
        $this->assertSame('Computer Class', $payload['recording']['meeting_title']);
        $this->assertSame($teacher->name, $payload['recording']['recorded_by']['name']);
        $this->assertSame(720, $payload['recording']['duration_seconds']);
    }

    public function test_a_subject_meeting_records_into_its_subject_channel(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $meeting->update(['class_subject_id' => $subject->id]);
        $subjectChannel = Channel::factory()->create([
            'school_class_id' => $class->id,
            'class_subject_id' => $subject->id,
            'type' => ChannelType::Subject,
            'status' => ChannelStatus::Active,
        ]);
        $recording = $this->startRecording($teacher, $meeting->fresh(), 12);

        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);

        $this->assertSame($subjectChannel->id, Message::query()->where('meeting_recording_id', $recording->id)->value('channel_id'));
    }

    public function test_a_card_is_never_removed_from_the_channel_when_the_meeting_row_goes_away(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $cardId = Message::query()->where('meeting_recording_id', $recording->id)->value('id');

        $recording->forceFill(['channel_id' => null])->save();
        MeetingRecording::query()->whereKey($recording->id)->update(['meeting_id' => $meeting->id]);

        $this->assertNotNull(Message::query()->find($cardId));
    }

    public function test_the_channel_message_list_exposes_the_card_without_any_stored_file_reference(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);
        app(FinalizeMeetingRecording::class)->handle($recording);
        $channel = Channel::query()->where('school_class_id', $class->id)->first();

        $response = $this->actingAs($student)
            ->getJson(route('collaboration.messages.index', [$class, $channel]))
            ->assertOk();

        $messages = collect($response->json('messages'))->where('type', MessageType::MeetingRecording->value)->values();
        $this->assertCount(1, $messages);
        $recording_payload = $messages->first()['recording'];
        $this->assertSame('ready', $recording_payload['status']);
        $this->assertNotNull($recording_payload['playback_url']);

        $serialized = json_encode($messages->first());
        $this->assertStringNotContainsString('meeting-recordings/', $serialized);
        $this->assertStringNotContainsString('EG_test_egress', $serialized);
    }

    public function test_a_recording_card_cannot_be_edited_or_hidden_by_a_teacher(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $card = Message::query()->where('meeting_recording_id', $recording->id)->firstOrFail();

        $this->assertFalse($teacher->can('update', $card));
        $this->assertFalse($teacher->can('hide', $card));
        $this->assertTrue($card->isServerAuthored());
    }

    public function test_a_recording_card_cannot_be_hidden_through_the_api(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $card = Message::query()->where('meeting_recording_id', $recording->id)->firstOrFail();
        $channel = Channel::query()->where('school_class_id', $class->id)->first();

        $this->actingAs($teacher)
            ->patchJson(route('collaboration.messages.hide', [$class, $channel, $card]))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->patchJson(route('collaboration.messages.update', [$class, $channel, $card]), ['body' => 'Rewritten'])
            ->assertForbidden();
    }

    public function test_the_channel_may_be_read_by_a_class_student_but_not_by_an_outsider(): void
    {
        [$class, $meeting, $teacher, $student, , $outsiderStudent, $channel] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);

        $this->actingAs($student)
            ->getJson(route('collaboration.messages.index', [$class, $channel]))
            ->assertOk();

        $this->actingAs($outsiderStudent)
            ->getJson(route('collaboration.messages.index', [$class, $channel]))
            ->assertForbidden();
    }

    public function test_finalisation_is_idempotent_and_never_rewrites_a_settled_card(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);

        app(FinalizeMeetingRecording::class)->handle($recording);
        $readyAt = $recording->fresh()->ready_at;
        app(FinalizeMeetingRecording::class)->handle($recording);
        app(FinalizeMeetingRecording::class)->handle($recording);

        $this->assertSame(MeetingRecordingStatus::Ready, $recording->fresh()->status);
        $this->assertEquals($readyAt, $recording->fresh()->ready_at);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
    }

    public function test_a_collection_that_never_arrives_is_bounded_and_fails_rather_than_waiting_forever(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);

        // No provider output is ever written, and the bounded window has elapsed.
        $recording->forceFill(['stopped_at' => now()->subMinutes((int) config('meeting-recordings.collection_deadline_minutes') + 5)])->save();

        [$settled, $stillSettling] = app(FinalizeMeetingRecording::class)->handle($recording->fresh());

        $this->assertFalse($stillSettling);
        $this->assertSame(MeetingRecordingStatus::Failed, $settled->status);
        $this->assertSame(1, Message::query()->where('meeting_recording_id', $recording->id)->count());
        $this->assertSame('failed', $this->cardPayload($recording)['recording']['status']);
    }

    public function test_the_recording_reaches_ready_without_any_browser_being_open(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::DurationReached);
        $this->publishProviderOutput($recording);

        // Nothing but the queued job runs from here.
        (new FinalizeMeetingRecordingOutput($recording->id))->handle(app(FinalizeMeetingRecording::class));

        $this->assertSame(MeetingRecordingStatus::Ready, $recording->fresh()->status);
        $this->assertNotNull($this->cardPayload($recording)['recording']['playback_url']);
    }

    /**
     * @return array<string, mixed>
     */
    private function cardPayload(MeetingRecording $recording): array
    {
        $message = Message::query()->where('meeting_recording_id', $recording->id)->firstOrFail();

        return app(\App\Support\MessagePayload::class)->make($message);
    }
}
