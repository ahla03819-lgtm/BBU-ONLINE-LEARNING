<?php

/**
 * Remove what the runtime verification put into the development database.
 *
 * Two phases, both deliberately narrow so they cannot touch data that existed
 * before the verification ran:
 *
 *  1. exactly the rows recorded in the seed's manifest, by id;
 *  2. rows stranded by an earlier, interrupted verification pass, matched only on
 *     the names and addresses this harness itself generates.
 *
 * Usage: php runtime/cleanup.php [manifest.json]
 */

use App\Models\Channel;
use App\Models\ChannelReadState;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\LiveKitWebhookEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingJoinRequest;
use App\Models\MeetingParticipant;
use App\Models\MeetingRecording;
use App\Models\MeetingScreenShareRequest;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$removed = [];
$count = function (string $key, int $n) use (&$removed) {
    $removed[$key] = ($removed[$key] ?? 0) + $n;
};

// Only ever these two names and this address pattern.
$userIds = User::query()
    ->where('email', 'like', 'teacher-sreymom-%@bbu.edu.kh')
    ->orWhere('email', 'like', 'student-sopheak-%@bbu.edu.kh')
    ->pluck('id');
$classIds = SchoolClass::query()->where('name', 'like', 'Computer Class %')->pluck('id');

// ---------------------------------------------------------------------------
// Phase 1: the run recorded in the manifest, removed by id.
// ---------------------------------------------------------------------------
$path = $argv[1] ?? (getenv('RUNTIME_MANIFEST') ?: sys_get_temp_dir().'/rt-manifest.json');
$manifest = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];

if ($manifest !== []) {
    $meetingIds = array_filter([$manifest['meeting_id'] ?? 0]);
    $participantIds = $manifest['participant_ids'] ?? [];
    $channelIds = $manifest['channel_ids'] ?? [];
    $recordingIds = MeetingRecording::query()->whereIn('meeting_id', $meetingIds)->pluck('id');
    $messageIds = Message::query()->whereIn('channel_id', $channelIds)->pluck('id')
        ->merge(Message::query()->whereIn('meeting_recording_id', $recordingIds));

    $count('messages', MessageReaction::query()->whereIn('message_id', $messageIds)->delete());
    $count('messages', Message::query()->whereIn('id', $messageIds)->delete());
    $count('read_states', ChannelReadState::query()->whereIn('channel_id', $channelIds)->delete());
    $count('webhooks', LiveKitWebhookEvent::query()->whereIn('egress_id', $recordingIds)->delete());
    $count('screen_share_requests', MeetingScreenShareRequest::query()->whereIn('meeting_id', $meetingIds)->delete());
    $count('attendance_sessions', MeetingAttendanceSession::query()->whereIn('meeting_participant_id', $participantIds)->delete());
    $count('join_requests', MeetingJoinRequest::query()->whereIn('meeting_id', $meetingIds)->delete());
    $count('participants', MeetingParticipant::query()->whereIn('id', $participantIds)->delete());
    $count('recordings', MeetingRecording::query()->whereIn('id', $recordingIds)->delete());
    $count('meetings', Meeting::query()->whereIn('id', $meetingIds)->delete());
    $count('channels', Channel::query()->whereIn('id', $channelIds)->delete());
    $count('subject_assignments', TeacherClassSubjectAssignment::query()->whereKey($manifest['subject_assignment_id'] ?? 0)->delete());
    $count('class_assignments', TeacherClassAssignment::query()->whereKey($manifest['teacher_class_assignment_id'] ?? 0)->delete());
    $count('subjects', ClassSubject::query()->whereKey($manifest['class_subject_id'] ?? 0)->delete());
    $count('enrollments', Enrollment::query()->whereKey($manifest['enrollment_id'] ?? 0)->delete());
    $count('student_profiles', StudentProfile::query()->whereKey($manifest['student_profile_id'] ?? 0)->delete());
    $count('teacher_profiles', TeacherProfile::query()->whereKey($manifest['teacher_profile_id'] ?? 0)->delete());
    $count('classes', SchoolClass::query()->whereIn('id', $classIds)->delete());
    $count('users', User::query()->whereIn('id', $manifest['user_ids'] ?? [])->delete());

    @unlink($path);
} else {
    $removed['manifest'] = 'absent, swept by pattern only';
}

// ---------------------------------------------------------------------------
// Phase 2: anything an earlier interrupted pass stranded. Still matched only on
// the class name and the two addresses this harness generates.
// ---------------------------------------------------------------------------
$classIds = SchoolClass::query()->where('name', 'like', 'Computer Class %')->pluck('id');
$userIds = User::query()
    ->where('email', 'like', 'teacher-sreymom-%@bbu.edu.kh')
    ->orWhere('email', 'like', 'student-sotheak-%@bbu.edu.kh')
    ->orWhere('email', 'like', 'student-sopheak-%@bbu.edu.kh')
    ->pluck('id');

$meetingIds = Meeting::query()->whereIn('school_class_id', $classIds)->pluck('id');
$subjectIds = ClassSubject::query()->whereIn('school_class_id', $classIds)->pluck('id');
$channelIds = Channel::query()->whereIn('school_class_id', $classIds)->pluck('id');
$participantIds = MeetingParticipant::query()->whereIn('meeting_id', $meetingIds)->pluck('id');
$studentProfileIds = StudentProfile::query()->whereIn('user_id', $userIds)->pluck('id');
$teacherProfileIds = TeacherProfile::query()->whereIn('user_id', $userIds)->pluck('id');

$count('messages', Message::query()->whereIn('channel_id', $channelIds)->delete());
$count('read_states', ChannelReadState::query()->whereIn('channel_id', $channelIds)->delete());
$count('webhooks', LiveKitWebhookEvent::query()->whereIn('livekit_room_name', Meeting::query()->whereIn('id', $meetingIds)->pluck('livekit_room_name'))->delete());
$count('screen_share_requests', MeetingScreenShareRequest::query()->whereIn('meeting_id', $meetingIds)->delete());
$count('attendance_sessions', MeetingAttendanceSession::query()->whereIn('meeting_participant_id', $participantIds)->delete());
$count('join_requests', MeetingJoinRequest::query()->whereIn('meeting_id', $meetingIds)->delete());
$count('participants', MeetingParticipant::query()->whereIn('id', $participantIds)->delete());
$count('meetings', Meeting::query()->whereIn('id', $meetingIds)->delete());
$count('channels', Channel::query()->whereIn('id', $channelIds)->delete());
$count('subject_assignments', TeacherClassSubjectAssignment::query()->whereIn('class_subject_id', $subjectIds)->delete());
$count('class_assignments', TeacherClassAssignment::query()->whereIn('school_class_id', $classIds)->delete());
$count('subjects', ClassSubject::query()->whereIn('id', $subjectIds)->delete());
$count('enrollments', Enrollment::query()->whereIn('school_class_id', $classIds)->delete());
$count('student_profiles', StudentProfile::query()->whereIn('id', $studentProfileIds)->delete());
$count('teacher_profiles', TeacherProfile::query()->whereIn('id', $teacherProfileIds)->delete());
$count('classes', SchoolClass::query()->whereIn('id', $classIds)->delete());
$count('users', User::query()->whereIn('id', $userIds)->delete());

// ---------------------------------------------------------------------------
// The files the provider wrote.
// ---------------------------------------------------------------------------
$disk = Storage::disk((string) config('meeting-recordings.disk'));
foreach ($disk->files('meeting-recordings') as $file) {
    $disk->delete($file);
    $removed['stored_objects'] = ($removed['stored_objects'] ?? 0) + 1;
}
$disk->deleteDirectory('probe');

echo json_encode(array_filter($removed), JSON_PRETTY_PRINT), PHP_EOL;
