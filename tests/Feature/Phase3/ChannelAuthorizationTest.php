<?php

namespace Tests\Feature\Phase3;

use App\Actions\Collaboration\ProvisionDefaultChannels;
use App\Actions\People\AssignTeacherToClass;
use App\Actions\People\AssignTeacherToClassSubject;
use App\Actions\People\EndEnrollment;
use App\Actions\People\EnrollStudent;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelAuthorizationTest extends TestCase
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

        return SchoolClass::factory()->create(['academic_year_id' => $year, 'grade_level_id' => GradeLevel::factory(), 'status' => SchoolClassStatus::Active]);
    }

    public function test_current_class_teacher_can_manage_custom_channel_but_subject_only_teacher_cannot(): void
    {
        $class = $this->activeClass();
        app(ProvisionDefaultChannels::class)->handle($class);
        $classUser = User::factory()->create();
        $classUser->assignRole('Teacher');
        $classTeacher = TeacherProfile::factory()->create(['user_id' => $classUser]);
        app(AssignTeacherToClass::class)->handle($classTeacher, $class, '2026-09-01');
        $this->actingAs($classUser)->post("/collaboration/classes/{$class->id}/channels", ['name' => 'Projects', 'slug' => 'projects'])->assertRedirect();
        $subjectUser = User::factory()->create();
        $subjectUser->assignRole('Teacher');
        $subjectTeacher = TeacherProfile::factory()->create(['user_id' => $subjectUser]);
        $classSubject = ClassSubject::factory()->create(['school_class_id' => $class, 'subject_id' => Subject::factory(), 'status' => 'active']);
        app(AssignTeacherToClassSubject::class)->handle($subjectTeacher, $classSubject, '2026-09-01');
        $this->actingAs($subjectUser)->post("/collaboration/classes/{$class->id}/channels", ['name' => 'Forbidden', 'slug' => 'forbidden'])->assertForbidden();
    }

    public function test_historical_relationships_and_closed_academics_deny_access(): void
    {
        $class = $this->activeClass();
        app(ProvisionDefaultChannels::class)->handle($class);
        $studentUser = User::factory()->create();
        $studentUser->assignRole('Student');
        $student = StudentProfile::factory()->create(['user_id' => $studentUser]);
        $enrollment = app(EnrollStudent::class)->handle($student, $class, '2026-09-01');
        $this->actingAs($studentUser)->get("/collaboration/classes/{$class->id}")->assertOk();
        app(EndEnrollment::class)->handle($enrollment, '2026-10-01', 'Ended');
        $this->actingAs($studentUser)->get("/collaboration/classes/{$class->id}")->assertForbidden();
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('Teacher');
        $teacher = TeacherProfile::factory()->create(['user_id' => $teacherUser]);
        app(AssignTeacherToClass::class)->handle($teacher, $class, '2026-09-01');
        $class->update(['status' => SchoolClassStatus::Closed]);
        $this->actingAs($teacherUser)->get("/collaboration/classes/{$class->id}")->assertForbidden();
    }

    public function test_nested_cross_class_channel_is_not_exposed(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $first = $this->activeClass();
        $second = SchoolClass::factory()->create(['academic_year_id' => $first->academic_year_id, 'status' => SchoolClassStatus::Active]);
        app(ProvisionDefaultChannels::class)->handle($first);
        app(ProvisionDefaultChannels::class)->handle($second);
        $channel = $second->channels()->first();
        $this->actingAs($admin)->get("/collaboration/classes/{$first->id}/channels/{$channel->id}")->assertNotFound();
    }

    public function test_default_channel_cannot_be_archived_while_class_is_active(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $class = $this->activeClass();
        app(ProvisionDefaultChannels::class)->handle($class);
        $channel = $class->channels()->first();
        $this->actingAs($admin)->patch("/collaboration/classes/{$class->id}/channels/{$channel->id}/archive")->assertForbidden();
    }
}
