<?php

namespace Tests\Feature;

use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingStatus;
use App\Models\MeetingRecording;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Phase20\RecordingTestCase;

class RecordingTrashTest extends RecordingTestCase
{
    use RefreshDatabase;

    public function test_teacher_can_soft_delete_recording(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Ready,
            'storage_disk' => 'local',
            'storage_path' => 'test/rec1.mp4',
        ]);
        Storage::disk('local')->put('test/rec1.mp4', 'test');

        $this->actingAs($teacher)
            ->postJson("/recordings/{$recording->public_uuid}/delete")
            ->assertOk();

        $this->assertTrue($recording->fresh()->trashed());
        $this->assertTrue(Storage::disk('local')->exists('test/rec1.mp4'));
    }

    public function test_student_cannot_delete(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Ready,
            'storage_disk' => 'local',
            'storage_path' => 'test/rec2.mp4',
        ]);
        Storage::disk('local')->put('test/rec2.mp4', 'test');

        $this->actingAs($student)
            ->postJson("/recordings/{$recording->public_uuid}/delete")
            ->assertForbidden();
    }

    public function test_trashed_recording_not_playable(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Ready,
            'storage_disk' => 'local',
            'storage_path' => 'test/rec3.mp4',
        ]);
        Storage::disk('local')->put('test/rec3.mp4', 'test');

        $this->actingAs($teacher)
            ->postJson("/recordings/{$recording->public_uuid}/delete")
            ->assertOk();

        $this->actingAs($student)
            ->get(route('meetings.recordings.play', [$class, $meeting, $recording->fresh()]))
            ->assertStatus(404);
    }

    public function test_restore_works(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Ready,
            'storage_disk' => 'local',
            'storage_path' => 'test/rec4.mp4',
        ]);
        Storage::disk('local')->put('test/rec4.mp4', 'test');

        $this->actingAs($teacher)
            ->postJson("/recordings/{$recording->public_uuid}/delete")
            ->assertOk();

        $this->actingAs($teacher)
            ->postJson("/recordings/{$recording->public_uuid}/restore")
            ->assertOk();

        $this->assertFalse($recording->fresh()->trashed());
    }

    public function test_non_admin_cannot_purge(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Ready,
            'storage_disk' => 'local',
            'storage_path' => 'test/rec5.mp4',
        ]);
        Storage::disk('local')->put('test/rec5.mp4', 'test');

        $this->actingAs($teacher)
            ->postJson("/recordings/{$recording->public_uuid}/delete")
            ->assertOk();

        $this->actingAs($teacher)
            ->postJson("/recordings/{$recording->public_uuid}/purge")
            ->assertForbidden();
    }

    public function test_permanent_purge_is_safely_disabled_without_touching_fake_storage_or_metadata(): void
    {
        [$class, $meeting, $teacher, $student, $outsiderTeacher, $outsiderStudent, $channel, $admin] = $this->scenario();
        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'status' => MeetingRecordingStatus::Ready,
            'storage_disk' => 'local',
            'storage_path' => 'test/rec6.mp4',
        ]);
        Storage::disk('local')->put('test/rec6.mp4', 'test');
        $message = Message::factory()->create(['channel_id' => $channel->id, 'meeting_recording_id' => $recording->id]);

        $this->actingAs($teacher)
            ->postJson("/recordings/{$recording->public_uuid}/delete")
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/recordings/{$recording->public_uuid}/purge")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Permanent recording purge is temporarily unavailable while durable media-cleanup recovery is being designed.');

        $this->assertNotNull(MeetingRecording::withTrashed()->find($recording->id));
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'meeting_recording_id' => $recording->id]);
        Storage::disk('local')->assertExists('test/rec6.mp4');
    }

    public function test_guests_cannot_access_any_recording_trash_endpoint(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = MeetingRecording::factory()->create(['meeting_id' => $meeting->id]);

        $this->get(route('recordings.trash.index'))->assertRedirect(route('login'));
        foreach (['delete', 'restore', 'purge'] as $action) {
            $this->postJson("/recordings/{$recording->public_uuid}/{$action}")->assertUnauthorized();
        }
    }

    public function test_trash_listing_is_authorized_scoped_and_does_not_serialize_storage_metadata(): void
    {
        [$class, $meeting, $teacher, $student, $outsiderTeacher] = $this->scenario();
        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'storage_disk' => 'local',
            'storage_path' => 'private/not-for-trash-ui.mp4',
        ]);
        $recording->delete();

        $this->actingAs($student)->get(route('recordings.trash.index'))->assertForbidden();
        $this->actingAs($outsiderTeacher)->get(route('recordings.trash.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Recordings/Trash')
            ->has('recordings.data', 0));
        $this->actingAs($teacher)->get(route('recordings.trash.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Recordings/Trash')
            ->has('recordings.data', 1)
            ->where('recordings.data.0.public_uuid', $recording->public_uuid)
            ->missing('recordings.data.0.storage_disk')
            ->missing('recordings.data.0.storage_path'));
    }

    public function test_non_admin_cannot_purge_even_with_the_explicit_permission(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $teacher->givePermissionTo('recordings.purge');
        $recording = MeetingRecording::factory()->create(['meeting_id' => $meeting->id]);
        $recording->delete();

        $this->actingAs($teacher)
            ->postJson("/recordings/{$recording->public_uuid}/purge")
            ->assertForbidden();
    }

    public function test_administrator_requires_a_trash_permission_to_list_recordings(): void
    {
        [$class, $meeting, $teacher, $student, $outsiderTeacher, $outsiderStudent, $channel, $admin] = $this->scenario();
        $role = Role::findByName('Admin');
        $role->revokePermissionTo('recordings.restore');
        $role->revokePermissionTo('recordings.purge');

        $this->actingAs($admin)->get(route('recordings.trash.index'))->assertForbidden();
    }

    public function test_safely_disabled_purge_leaves_fake_s3_objects_untouched(): void
    {
        config(['meeting-recordings.disk' => 's3']);
        Storage::fake('s3');
        [$class, $meeting, $teacher, $student, $outsiderTeacher, $outsiderStudent, $channel, $admin] = $this->scenario();
        $recording = MeetingRecording::factory()->create([
            'meeting_id' => $meeting->id,
            'storage_disk' => 's3',
            'storage_path' => 'test/recording.mp4',
        ]);
        Storage::disk('s3')->put($recording->storage_path, 'fake-s3-media');
        $recording->delete();

        $this->actingAs($admin)->postJson("/recordings/{$recording->public_uuid}/purge")->assertStatus(409);

        Storage::disk('s3')->assertExists($recording->storage_path);
        $this->assertNotNull(MeetingRecording::withTrashed()->find($recording->id));
    }
}
