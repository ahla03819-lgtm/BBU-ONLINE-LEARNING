<?php

namespace Tests\Feature\Phase3;

use App\Actions\Academics\SyncClassSubjects;
use App\Actions\Collaboration\CreateAnnouncement;
use App\Actions\Collaboration\ProvisionDefaultChannels;
use App\Actions\People\AssignTeacherToClass;
use App\Actions\People\AssignTeacherToClassSubject;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function activeClass(): SchoolClass
    {
        $year = AcademicYear::factory()->active()->create();
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'grade_level_id' => GradeLevel::factory(), 'status' => SchoolClassStatus::Active]);
        app(ProvisionDefaultChannels::class)->handle($class);

        return $class;
    }

    public function test_subject_teacher_can_publish_only_in_assigned_subject_channel(): void
    {
        $class = $this->activeClass();
        $subject = Subject::factory()->create();
        app(SyncClassSubjects::class)->handle($class, [$subject->id], '2026-09-01');
        $subjectChannel = $class->channels()->where('type', 'subject')->firstOrFail();
        $announcementChannel = $class->channels()->where('type', 'announcement')->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole('Teacher');
        $teacher = TeacherProfile::factory()->create(['user_id' => $user]);
        app(AssignTeacherToClassSubject::class)->handle($teacher, $subjectChannel->classSubject, '2026-09-01');
        $this->actingAs($user)->post("/collaboration/classes/{$class->id}/channels/{$subjectChannel->id}/announcements", ['title' => 'Subject notice', 'body' => 'Allowed'])->assertRedirect();
        $this->actingAs($user)->post("/collaboration/classes/{$class->id}/channels/{$announcementChannel->id}/announcements", ['title' => 'Class notice', 'body' => 'Forbidden'])->assertForbidden();
    }

    public function test_current_class_teacher_can_publish_class_announcement_and_student_is_read_only(): void
    {
        $class = $this->activeClass();
        $channel = $class->channels()->where('type', 'announcement')->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole('Teacher');
        $teacher = TeacherProfile::factory()->create(['user_id' => $user]);
        app(AssignTeacherToClass::class)->handle($teacher, $class, '2026-09-01');
        $this->actingAs($user);
        $announcement = app(CreateAnnouncement::class)->handle($channel, ['title' => 'Notice', 'body' => 'Class notice']);
        $this->patch("/collaboration/classes/{$class->id}/channels/{$channel->id}/announcements/{$announcement->id}/publish")->assertRedirect();
        $studentUser = User::factory()->create();
        $studentUser->assignRole('Student');
        StudentProfile::factory()->create(['user_id' => $studentUser]);
        $this->actingAs($studentUser)->post("/collaboration/classes/{$class->id}/channels/{$channel->id}/announcements", ['title' => 'No', 'body' => 'No'])->assertForbidden();
    }
}
