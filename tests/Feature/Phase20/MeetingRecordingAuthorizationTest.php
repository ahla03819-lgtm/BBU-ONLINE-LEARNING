<?php

namespace Tests\Feature\Phase20;

use App\Actions\Recordings\StopMeetingRecording;
use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingRecordingStopReason;
use App\Enums\MeetingStatus;
use App\Jobs\FinalizeMeetingRecordingOutput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class MeetingRecordingAuthorizationTest extends RecordingTestCase
{
    public function test_the_assigned_host_can_start_a_recording(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->postJson(route('meetings.recordings.store', [$class, $meeting->uuid]), ['duration_minutes' => 12])
            ->assertCreated()
            ->assertJsonPath('recording.status', 'recording');
    }

    public function test_an_administrator_can_start_a_recording(): void
    {
        [$class, $meeting, , , , , , $admin] = $this->scenario();

        $this->actingAs($admin)
            ->postJson(route('meetings.recordings.store', [$class, $meeting->uuid]), ['duration_minutes' => 12])
            ->assertCreated();
    }

    public function test_a_student_cannot_start_a_recording(): void
    {
        [$class, $meeting, , $student] = $this->scenario();

        $this->actingAs($student)
            ->postJson(route('meetings.recordings.store', [$class, $meeting->uuid]), ['duration_minutes' => 12])
            ->assertForbidden();

        $this->assertCount(0, $this->recordings->starts);
        $this->assertSame(0, \App\Models\MeetingRecording::query()->count());
    }

    public function test_a_teacher_assigned_to_another_class_cannot_start_a_recording(): void
    {
        [$class, $meeting, , , $outsiderTeacher] = $this->scenario();

        $this->actingAs($outsiderTeacher)
            ->postJson(route('meetings.recordings.store', [$class, $meeting->uuid]), ['duration_minutes' => 12])
            ->assertForbidden();

        $this->assertCount(0, $this->recordings->starts);
    }

    public function test_a_teacher_eligible_for_the_subject_but_not_the_assigned_host_cannot_start_a_recording(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $subject = \App\Models\ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $meeting->update(['class_subject_id' => $subject->id]);
        // A subject teacher is an eligible host in general, but recording is
        // reserved for the meeting's assigned host.
        $subjectTeacher = $this->roleUser('Teacher');
        $profile = \App\Models\TeacherProfile::factory()->create(['user_id' => $subjectTeacher->id]);
        \App\Models\TeacherClassSubjectAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'class_subject_id' => $subject->id,
        ]);

        $this->actingAs($subjectTeacher)
            ->postJson(route('meetings.recordings.store', [$class, $meeting->fresh()->uuid]), ['duration_minutes' => 12])
            ->assertForbidden();
        $this->assertCount(0, $this->recordings->starts);
    }

    public function test_a_student_cannot_stop_a_recording(): void
    {
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $this->startRecording($teacher, $meeting, 12);

        $this->actingAs($student)
            ->postJson(route('meetings.recordings.stop', [$class, $meeting->uuid]))
            ->assertForbidden();

        $this->assertSame(MeetingRecordingStatus::Recording, \App\Models\MeetingRecording::query()->first()->status);
        $this->assertCount(0, $this->recordings->stops);
    }

    public function test_a_teacher_assigned_to_another_class_cannot_stop_a_recording(): void
    {
        [$class, $meeting, $teacher, , $outsiderTeacher] = $this->scenario();
        $this->startRecording($teacher, $meeting, 12);

        $this->actingAs($outsiderTeacher)
            ->postJson(route('meetings.recordings.stop', [$class, $meeting->uuid]))
            ->assertForbidden();

        $this->assertCount(0, $this->recordings->stops);
    }

    public function test_an_unauthenticated_visitor_cannot_reach_any_recording_endpoint(): void
    {
        [$class, $meeting] = $this->scenario();

        $this->postJson(route('meetings.recordings.store', [$class, $meeting->uuid]), ['duration_minutes' => 12])->assertUnauthorized();
        $this->postJson(route('meetings.recordings.stop', [$class, $meeting->uuid]))->assertUnauthorized();
        $this->getJson(route('meetings.recordings.current', [$class, $meeting->uuid]))->assertUnauthorized();
    }

    public function test_an_authorised_student_can_watch_a_ready_recording(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);
        app(\App\Actions\Recordings\FinalizeMeetingRecording::class)->handle($recording);

        $response = $this->actingAs($student)
            ->get(route('meetings.recordings.play', [$class, $meeting->uuid, $recording->fresh()]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // A recording is never cached by anything shared or downstream.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('video/mp4', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_user_outside_the_class_cannot_watch_a_recording_even_with_its_public_reference(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, , , $outsiderStudent] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);
        app(\App\Actions\Recordings\FinalizeMeetingRecording::class)->handle($recording);

        $this->actingAs($outsiderStudent)
            ->get(route('meetings.recordings.play', [$class, $meeting->uuid, $recording->fresh()]))
            ->assertForbidden();
    }

    public function test_a_recording_cannot_be_played_before_it_is_ready(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        $this->actingAs($teacher)
            ->get(route('meetings.recordings.play', [$class, $meeting->uuid, $recording]))
            ->assertForbidden();
    }

    public function test_a_failed_recording_cannot_be_played(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->recordings->unreachable = true;
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $recording->forceFill([
            'storage_disk' => config('meeting-recordings.disk'),
            'storage_path' => 'meeting-recordings/x.mp4',
        ])->save();

        $this->actingAs($teacher)
            ->get(route('meetings.recordings.play', [$class, $meeting->uuid, $recording->fresh()]))
            ->assertForbidden();
    }

    public function test_a_recording_from_a_different_meeting_cannot_be_played_through_this_meeting(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);
        app(\App\Actions\Recordings\FinalizeMeetingRecording::class)->handle($recording);

        $otherMeeting = \App\Models\Meeting::factory()->active()->create([
            'school_class_id' => $class->id,
            'host_user_id' => $teacher->id,
        ]);

        $this->actingAs($teacher)
            ->get(route('meetings.recordings.play', [$class, $otherMeeting->uuid, $recording->fresh()]))
            ->assertNotFound();
    }

    public function test_a_missing_stored_object_is_not_reported_as_a_playable_recording(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        // The row claims to be ready but the object was never written.
        $recording->forceFill([
            'status' => MeetingRecordingStatus::Ready,
            'active_slot' => null,
            'storage_disk' => config('meeting-recordings.disk'),
            'storage_path' => 'meeting-recordings/missing.mp4',
            'mime_type' => 'video/mp4',
        ])->save();

        $this->actingAs($teacher)
            ->get(route('meetings.recordings.play', [$class, $meeting->uuid, $recording->fresh()]))
            ->assertNotFound();
    }

    public function test_a_student_cannot_see_the_start_control_in_the_room_page(): void
    {
        [$class, $meeting, , $student] = $this->scenario();

        $this->actingAs($student)
            ->get(route('meetings.room', [$class, $meeting->uuid]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('meeting.can_start_recording', false)
                ->where('meeting.recording', null));
    }

    public function test_a_host_sees_the_start_control_in_the_room_page(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->get(route('meetings.room', [$class, $meeting->uuid]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('meeting.can_start_recording', true));
    }

    public function test_the_recording_permission_is_granted_to_teachers_and_administrators_but_not_students(): void
    {
        $teacher = $this->roleUser('Teacher');
        $admin = $this->roleUser('Admin');
        $student = $this->roleUser('Student');

        $this->assertTrue($teacher->can('meetings.record'));
        $this->assertTrue($admin->can('meetings.record'));
        $this->assertFalse($student->can('meetings.record'));
    }

    public function test_a_super_admin_does_not_bypass_the_recording_policy(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $super = $this->roleUser('Super Admin');
        $recording = $this->startRecording($teacher, $meeting, 12);

        // MeetingRecording is one of the models excluded from the Super Admin gate
        // bypass, so a recording that is not watchable stays unplayable even for one.
        $this->assertTrue($super->can('view', $recording));
        $this->assertFalse($super->can('play', $recording));

        $this->expectException(AuthorizationException::class);
        app(\Illuminate\Contracts\Auth\Access\Gate::class)->forUser($super)->authorize('play', $recording);
    }

    public function test_a_removed_participant_cannot_reach_the_recording_state(): void
    {
        [$class, $meeting, , $student] = $this->scenario();
        $meeting->participants()->where('user_id', $student->id)->update(['removed_at' => now()]);

        $this->actingAs($student)
            ->get(route('meetings.room', [$class, $meeting->uuid]))
            ->assertForbidden();
    }

    public function test_the_recording_disk_is_private_and_never_the_public_disk(): void
    {
        $this->assertNotSame('public', config('meeting-recordings.disk'));
        Storage::disk((string) config('meeting-recordings.disk'))->put('probe.txt', 'x');
        $this->assertTrue(Storage::disk((string) config('meeting-recordings.disk'))->exists('probe.txt'));
    }

    public function test_the_leave_signal_only_stops_a_recording_the_leaver_started(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher, $student] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);

        // A different participant leaving must not touch the teacher's recording.
        $this->actingAs($student)->postJson(route('meetings.leave', [$class, $meeting->uuid]))->assertOk();

        $this->assertSame(MeetingRecordingStatus::Recording, $recording->fresh()->status);
        $this->assertCount(0, $this->recordings->stops);
    }

    public function test_a_recording_cannot_be_started_for_an_ended_meeting_even_for_the_host(): void
    {
        [$class, $meeting, $teacher] = $this->scenario();
        $meeting->update(['status' => MeetingStatus::Ending]);

        $this->actingAs($teacher)
            ->postJson(route('meetings.recordings.store', [$class, $meeting->uuid]), ['duration_minutes' => 12])
            ->assertForbidden();
    }

    public function test_the_collection_job_reports_ready_once_the_provider_output_is_readable(): void
    {
        Queue::fake();
        [$class, $meeting, $teacher] = $this->scenario();
        $recording = $this->startRecording($teacher, $meeting, 12);
        $this->providerCompletes();
        app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::Manual, $teacher);
        $this->publishProviderOutput($recording);

        (new FinalizeMeetingRecordingOutput($recording->id))->handle(app(\App\Actions\Recordings\FinalizeMeetingRecording::class));

        $this->assertSame(MeetingRecordingStatus::Ready, $recording->fresh()->status);
        $this->assertSame('video/mp4', $recording->fresh()->mime_type);
        $this->assertNotNull($recording->fresh()->ready_at);
    }
}
