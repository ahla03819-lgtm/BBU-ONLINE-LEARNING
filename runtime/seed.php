<?php

/**
 * Seed one real class, host teacher, student and live meeting for the runtime
 * verification, and print the credentials the HTTP harness needs.
 */

use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Channel;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\Subject;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app->make(Illuminate\Contracts\Console\Kernel::class)->call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

$year = AcademicYear::query()->where('active_slot', 1)->first() ?? AcademicYear::factory()->active()->create();
$subject = Subject::query()->firstOrCreate(['code' => 'CS101'], ['name' => 'Computer Science', 'is_active' => true]);

$makeUser = function (string $role, string $name) use (&$run) {
    $user = User::factory()->create([
        'name' => $name,
        // The application's own login rules: a @bbu.edu.kh address, verified and
        // approved, on an active account.
        'email' => str($name)->slug().'-'.$run.'@bbu.edu.kh',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
        'approved_at' => now(),
        'status' => \App\Enums\AccountStatus::Active,
    ]);
    $user->assignRole($role);

    return $user;
};

// A run suffix keeps each runtime pass on a fresh class while leaving the subject
// and academic year shared.
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$gradeLevel = \App\Models\GradeLevel::query()->where('is_active', true)->first()
    ?? \App\Models\GradeLevel::factory()->create(['name' => 'Runtime Grade '.$run]);
$class = SchoolClass::factory()->create([
    'grade_level_id' => $gradeLevel->id,
    'academic_year_id' => $year->id,
    'name' => 'Computer Class '.$run,
    'section' => 'A',
    'status' => SchoolClassStatus::Active,
]);
$classSubject = ClassSubject::factory()->create(['school_class_id' => $class->id, 'subject_id' => $subject->id]);

$teacher = $makeUser('Teacher', 'Teacher Sreymom');
$teacherProfile = TeacherProfile::factory()->create(['user_id' => $teacher->id]);
$classAssignment = TeacherClassAssignment::factory()->create(['teacher_profile_id' => $teacherProfile->id, 'school_class_id' => $class->id]);
$subjectAssignment = TeacherClassSubjectAssignment::factory()->create(['teacher_profile_id' => $teacherProfile->id, 'class_subject_id' => $classSubject->id]);

$student = $makeUser('Student', 'Student Sopheak');
$studentProfile = StudentProfile::factory()->create(['user_id' => $student->id]);
$enrollment = Enrollment::factory()->create([
    'student_profile_id' => $studentProfile->id,
    'academic_year_id' => $year->id,
    'school_class_id' => $class->id,
]);

// Provision the class and its subject the way the application does, so they carry
// the default channels a real class and subject always have.
app(\App\Actions\Collaboration\ProvisionDefaultChannels::class)->handle($class, $teacher->id);
$channel = $class->channels()->where('type', \App\Enums\ChannelType::General)->firstOrFail();

$meeting = Meeting::factory()->active()->create([
    'school_class_id' => $class->id,
    'class_subject_id' => $classSubject->id,
    'host_user_id' => $teacher->id,
    'created_by' => $teacher->id,
    'title' => 'Computer Class',
]);
MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $teacher->id, 'role' => 'host']);
MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $student->id]);

// Record exactly what this run created, so cleanup can remove precisely these rows
// and nothing that existed beforehand.
$manifest = getenv('RUNTIME_MANIFEST') ?: (sys_get_temp_dir().'/rt-manifest.json');
$userIds = [$teacher->id, $student->id];
file_put_contents($manifest, json_encode([
    'run' => $run,
    'class_id' => $class->id,
    'class_subject_id' => $classSubject->id,
    'meeting_id' => $meeting->id,
    'channel_ids' => Channel::query()->where('school_class_id', $class->id)->pluck('id')->all(),
    'participant_ids' => MeetingParticipant::query()->where('meeting_id', $meeting->id)->pluck('id')->all(),
    'teacher_profile_id' => $teacherProfile->id,
    'student_profile_id' => $studentProfile->id,
    'teacher_class_assignment_id' => $classAssignment->id,
    'subject_assignment_id' => $subjectAssignment->id,
    'enrollment_id' => $enrollment->id,
    'user_ids' => $userIds,
], JSON_PRETTY_PRINT));

echo json_encode([
    'class_id' => $class->id,
    'meeting_id' => $meeting->id,
    'meeting_uuid' => $meeting->uuid,
    'channel_id' => $channel->id,
    'room_name' => $meeting->livekit_room_name,
    'teacher' => ['id' => $teacher->id, 'email' => $teacher->email, 'name' => $teacher->name],
    'student' => ['id' => $student->id, 'email' => $student->email, 'name' => $student->name],
], JSON_PRETTY_PRINT), PHP_EOL;
