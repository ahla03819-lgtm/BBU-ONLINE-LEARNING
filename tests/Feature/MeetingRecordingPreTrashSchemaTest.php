<?php

namespace Tests\Feature;

use App\Enums\MessageType;
use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingRecordingStopReason;
use App\Actions\Recordings\ReconcileMeetingRecordingState;
use App\Models\Channel;
use App\Models\MeetingRecording;
use App\Models\Message;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Feature\Phase20\RecordingTestCase;

class MeetingRecordingPreTrashSchemaTest extends RecordingTestCase
{
    use RefreshDatabase;

    protected $realSchema;

    protected function simulateMissingTrashSchema(): void
    {
        $this->realSchema = Schema::getFacadeRoot();

        $mock = Mockery::mock(SchemaBuilder::class);
        $mock->shouldReceive('hasColumn')->with('meeting_recordings', 'deleted_at')->andReturn(false);

        Schema::swap($mock);
        MeetingRecording::resetTrashSchemaCache();
    }

    protected function tearDown(): void
    {
        if (isset($this->realSchema)) {
            Schema::swap($this->realSchema);
        }

        MeetingRecording::resetTrashSchemaCache();

        parent::tearDown();
    }

    public function test_channel_message_index_returns_200_without_trash_columns(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $channel = Channel::query()->where('school_class_id', $class->id)->first();

        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(\App\Actions\Recordings\StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);
        app(\App\Actions\Recordings\FinalizeMeetingRecording::class)->handle($recording);

        $this->simulateMissingTrashSchema();

        $this->actingAs($student)
            ->getJson(route('collaboration.messages.index', [$class, $channel]))
            ->assertOk();
    }

    public function test_normal_meeting_recording_queries_work_without_trash_columns(): void
    {
        [$class, $meeting] = $this->scenario();
        $this->simulateMissingTrashSchema();

        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Recording,
        ]);

        $this->assertNotNull(MeetingRecording::query()->where('id', $recording->id)->first());
        $this->assertNotNull(MeetingRecording::query()->activeFor($meeting->id)->first());
        $this->assertCount(1, MeetingRecording::query()->get());
    }

    public function test_reconcile_meeting_recording_state_works_without_trash_columns(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->simulateMissingTrashSchema();

        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Recording,
            'scheduled_stop_at' => now()->subMinute(),
        ]);

        $result = app(ReconcileMeetingRecordingState::class)->handle(false);

        $this->assertArrayHasKey('stopped', $result);
        $this->assertArrayHasKey('finalized', $result);
    }

    public function test_trash_delete_fails_safely_without_trash_columns(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->simulateMissingTrashSchema();

        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Ready,
        ]);

        $this->actingAs($teacher)
            ->postJson(route('recordings.destroy', $recording))
            ->assertStatus(409);
    }

    public function test_trash_restore_fails_safely_without_trash_columns(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $this->simulateMissingTrashSchema();

        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Ready,
        ]);

        $this->actingAs($teacher)
            ->postJson(route('recordings.restore', $recording))
            ->assertStatus(409);
    }

    public function test_trash_purge_fails_safely_without_trash_columns(): void
    {
        [$class, $meeting, $teacher, , , , , $admin] = $this->scenario();
        $this->simulateMissingTrashSchema();

        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Ready,
        ]);

        $this->actingAs($admin)
            ->postJson(route('recordings.purge', $recording))
            ->assertStatus(409);
    }

    public function test_playback_authorization_still_enforced_without_trash_columns(): void
    {
        [$class, $meeting, $teacher, , , $outsiderStudent] = $this->scenario();
        $this->simulateMissingTrashSchema();

        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Ready,
            'storage_disk' => 'local',
            'storage_path' => 'test/rec.mp4',
        ]);

        $this->actingAs($outsiderStudent)
            ->get(route('meetings.recordings.play', [$class, $meeting, $recording]))
            ->assertStatus(403);
    }
}
