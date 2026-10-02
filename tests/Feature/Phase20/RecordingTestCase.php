<?php

namespace Tests\Feature\Phase20;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\MeetingRecordingProviderStatus;
use App\Enums\MeetingRecordingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Channel;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\MeetingRecording;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRecordingManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Shared scenario for the meeting-recording suites.
 *
 * It builds one live meeting with an assigned host teacher, an enrolled student, a
 * teacher from an unrelated class, an administrator, and a student who is not in
 * this class, then binds an in-memory recording provider so no test ever needs a
 * running LiveKit Egress service.
 */
abstract class RecordingTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeLiveKitRecordingManager $recordings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->recordings = new FakeLiveKitRecordingManager();
        $this->app->instance(LiveKitRecordingManager::class, $this->recordings);
        Storage::fake((string) config('meeting-recordings.disk'));
    }

    /**
     * @return array{0: SchoolClass, 1: Meeting, 2: User, 3: User, 4: User, 5: User, 6: Channel}
     */
    protected function scenario(): array
    {
        $class = $this->activeClass();
        $teacher = $this->teacher($class);
        $student = $this->student($class);
        $outsiderTeacher = $this->teacher($this->activeClass());
        $outsiderStudent = $this->student($this->activeClass());
        $admin = $this->roleUser('Admin');
        $meeting = Meeting::factory()->active()->create([
            'school_class_id' => $class->id,
            'host_user_id' => $teacher->id,
            'created_by' => $teacher->id,
        ]);
        MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $student->id]);
        $channel = Channel::factory()->create([
            'school_class_id' => $class->id,
            'type' => ChannelType::General,
            'status' => ChannelStatus::Active,
        ]);

        return [$class, $meeting, $teacher, $student, $outsiderTeacher, $outsiderStudent, $channel, $admin];
    }

    /** Start a recording through the real action, exactly as the endpoint does. */
    protected function startRecording(User $actor, Meeting $meeting, ?int $minutes = 12): MeetingRecording
    {
        return app(\App\Actions\Recordings\StartMeetingRecording::class)->handle($actor, $meeting, $minutes);
    }

    /**
     * Stand in for the provider having finished writing its file.
     *
     * The path comes from the recording's own stored provider_output_path, exactly
     * as the application will look for it later, so a test cannot accidentally paper
     * over the application having decided the wrong path.
     */
    protected function publishProviderOutput(MeetingRecording $recording, string $contents = 'fake-mp4-bytes'): MeetingRecording
    {
        $path = $recording->provider_output_path;
        abort_if(! is_string($path) || $path === '', 'The recording never stored a provider output path.');

        Storage::disk((string) config('meeting-recordings.disk'))->put($path, $contents);

        return $recording->fresh();
    }

    protected function activeClass(): SchoolClass
    {
        $year = AcademicYear::query()->where('active_slot', 1)->first() ?? AcademicYear::factory()->active()->create();

        return SchoolClass::factory()->create(['academic_year_id' => $year->id, 'status' => SchoolClassStatus::Active]);
    }

    protected function teacher(SchoolClass $class, bool $assigned = true): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        if ($assigned) {
            TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id]);
        }

        return $user;
    }

    protected function student(SchoolClass $class): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
        ]);

        return $user;
    }

    protected function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * A provider that answered, produced output, and is reachable again.
     */
    protected function providerCompletes(): void
    {
        $this->recordings->unreachable = false;
        $this->recordings->stopStatus = MeetingRecordingProviderStatus::Complete;
        $this->recordings->ready();
    }
}
