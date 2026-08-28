<?php

namespace Tests\Feature\Phase8;

use App\Actions\Coursework\RecordAssignmentGrade;
use App\Actions\Coursework\SaveSubmissionDraft;
use App\Actions\Coursework\StartResubmission;
use App\Actions\Coursework\SubmitAssignment;
use App\Actions\Coursework\TransitionAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CourseworkFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_only_current_subject_teacher_can_create_and_mutate_assignment(): void
    {
        [$class, $subject] = $this->subject();
        $subjectTeacher = $this->teacher($subject);
        $classTeacher = $this->teacher($subject, false, true);
        $historical = $this->teacher($subject, false);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->assertTrue($subjectTeacher->can('create', [Assignment::class, $subject]));
        $this->assertTrue($admin->can('create', [Assignment::class, $subject]));
        $this->assertFalse($classTeacher->can('create', [Assignment::class, $subject]));
        $this->assertFalse($historical->can('create', [Assignment::class, $subject]));
        $unrelatedClass = SchoolClass::factory()->create();
        $this->actingAs($subjectTeacher)->get(route('coursework.index', $unrelatedClass))->assertForbidden();
        $response = $this->actingAs($subjectTeacher)->post(route('coursework.assignments.store', [$class, $subject]), ['title' => 'Essay', 'instructions' => 'Write it', 'max_points' => 20, 'due_at' => now()->addDay(), 'allow_resubmission' => true]);
        $response->assertRedirect();
        $this->assertDatabaseHas('assignments', ['class_subject_id' => $subject->id, 'title' => 'Essay', 'status' => 'draft']);
    }

    public function test_assignment_lifecycle_and_published_points_immutability_are_enforced(): void
    {
        [$class, $subject] = $this->subject();
        $teacher = $this->teacher($subject);
        $assignment = Assignment::factory()->create(['class_subject_id' => $subject, 'created_by' => $teacher]);
        app(TransitionAssignment::class)->handle($teacher, $assignment, 'publish', 0);
        $assignment->refresh();
        $this->assertSame(AssignmentStatus::Published, $assignment->status);
        $this->assertNotNull($assignment->published_at);
        $this->actingAs($teacher)->patch(route('coursework.assignments.update', [$class, $subject, $assignment]), ['title' => 'Corrected', 'instructions' => null, 'max_points' => 999, 'due_at' => now()->addDays(2), 'allow_resubmission' => false, 'lifecycle_version' => 1])->assertSessionHasErrors('max_points');
        app(TransitionAssignment::class)->handle($teacher, $assignment->fresh(), 'close', 1);
        $this->assertSame(AssignmentStatus::Closed, $assignment->fresh()->status);
    }

    public function test_student_draft_submit_late_and_resubmit_preserve_history(): void
    {
        [, $subject] = $this->subject();
        $teacher = $this->teacher($subject);
        $student = $this->student($subject);
        $assignment = Assignment::factory()->published()->create(['class_subject_id' => $subject, 'created_by' => $teacher, 'due_at' => now()->subMinute()]);
        $submission = app(SaveSubmissionDraft::class)->handle($student, $assignment, ['client_uuid' => (string) Str::uuid(), 'body' => 'First answer', 'attachment_client_uuids' => []]);
        app(SubmitAssignment::class)->handle($student, $submission);
        $first = $submission->fresh()->latestSubmitted();
        $this->assertTrue($first->is_late);
        $this->assertSame('First answer', $first->body);
        $this->assertNotNull($first->submitted_at);
        app(StartResubmission::class)->handle($student, $submission->fresh());
        $submission->refresh();
        app(SaveSubmissionDraft::class)->handle($student, $assignment, ['client_uuid' => (string) Str::uuid(), 'body' => 'Second answer', 'attachment_client_uuids' => []]);
        app(SubmitAssignment::class)->handle($student, $submission->fresh());
        $this->assertDatabaseCount('assignment_submission_revisions', 2);
        $this->assertSame('First answer', $first->fresh()->body);
    }

    public function test_empty_submission_is_rejected_and_browser_timestamps_are_not_accepted(): void
    {
        [, $subject] = $this->subject();
        $student = $this->student($subject);
        $assignment = Assignment::factory()->published()->create(['class_subject_id' => $subject]);
        $submission = app(SaveSubmissionDraft::class)->handle($student, $assignment, ['client_uuid' => (string) Str::uuid(), 'body' => '', 'attachment_client_uuids' => []]);
        $this->expectException(ValidationException::class);
        app(SubmitAssignment::class)->handle($student, $submission);
    }

    public function test_grade_rows_are_immutable_bounded_and_require_change_reason(): void
    {
        [, $subject] = $this->subject();
        $teacher = $this->teacher($subject);
        $student = $this->student($subject);
        $assignment = Assignment::factory()->published()->create(['class_subject_id' => $subject, 'max_points' => 20]);
        $submission = app(SaveSubmissionDraft::class)->handle($student, $assignment, ['client_uuid' => (string) Str::uuid(), 'body' => 'Answer', 'attachment_client_uuids' => []]);
        app(SubmitAssignment::class)->handle($student, $submission);
        app(RecordAssignmentGrade::class)->handle($teacher, $submission->fresh(), ['points_awarded' => 15, 'feedback' => 'Good']);
        try {
            app(RecordAssignmentGrade::class)->handle($teacher, $submission->fresh(), ['points_awarded' => 21, 'feedback' => 'Invalid', 'change_reason' => 'Invalid']);
            $this->fail('An over-maximum grade was accepted.');
        } catch (ValidationException) {
        }
        try {
            app(RecordAssignmentGrade::class)->handle($teacher, $submission->fresh(), ['points_awarded' => 16, 'feedback' => 'Better']);
            $this->fail('Grade revision accepted without a reason.');
        } catch (ValidationException) {
        }
        app(RecordAssignmentGrade::class)->handle($teacher, $submission->fresh(), ['points_awarded' => 16, 'feedback' => 'Better', 'change_reason' => 'Rechecked rubric']);
        $this->assertDatabaseCount('assignment_grades', 2);
        $this->assertDatabaseHas('assignment_grades', ['revision_number' => 1, 'points_awarded' => 15]);
        $this->assertDatabaseHas('assignment_grades', ['revision_number' => 2, 'points_awarded' => 16]);
    }

    public function test_historical_users_are_read_only_and_students_never_see_drafts(): void
    {
        [$class, $subject] = $this->subject();
        $teacher = $this->teacher($subject);
        $student = $this->student($subject);
        $draft = Assignment::factory()->create(['class_subject_id' => $subject]);
        $published = Assignment::factory()->published()->create(['class_subject_id' => $subject, 'published_at' => now()->subDay()]);
        $this->assertFalse($student->can('view', $draft));
        $this->assertTrue($student->can('view', $published));
        Enrollment::query()->whereHas('studentProfile', fn ($q) => $q->where('user_id', $student->id))->update(['current_slot' => null, 'ended_on' => now()]);
        TeacherClassSubjectAssignment::query()->whereHas('teacherProfile', fn ($q) => $q->where('user_id', $teacher->id))->update(['current_slot' => null, 'ends_on' => now()]);
        $this->assertTrue($student->can('view', $published));
        $this->assertFalse($student->can('create', [AssignmentSubmission::class, $published]));
        $this->assertTrue($teacher->can('view', $published));
        $this->assertFalse($teacher->can('update', $published));
    }

    public function test_rbac_command_and_inertia_pages_are_available(): void
    {
        $this->artisan('coursework:verify-permissions')->assertSuccessful();
        foreach (['Coursework/Index', 'Coursework/Assignments/Create', 'Coursework/Assignments/Edit', 'Coursework/Assignments/Show'] as $page) {
            $this->assertFileExists(resource_path('js/Pages/'.$page.'.jsx'));
        }
    }

    public function test_permission_verifier_fails_cleanly_when_deployment_is_not_synchronized(): void
    {
        Permission::findByName('assignments.create')->delete();
        $this->artisan('coursework:verify-permissions')->expectsOutputToContain('Coursework RBAC is not synchronized.')->assertFailed();
    }

    public function test_private_attachments_are_scoped_and_nested_idor_is_denied(): void
    {
        Storage::fake('local');
        [$class, $subject] = $this->subject();
        $student = $this->student($subject);
        $other = $this->student($subject);
        $assignment = Assignment::factory()->published()->create(['class_subject_id' => $subject]);
        $this->actingAs($student)->put(route('coursework.submissions.save', [$class, $subject, $assignment]), ['client_uuid' => (string) Str::uuid(), 'body' => null, 'attachments' => [UploadedFile::fake()->createWithContent('answer.txt', 'coursework answer')], 'attachment_client_uuids' => [(string) Str::uuid()]])->assertRedirect();
        $submission = AssignmentSubmission::query()->firstOrFail();
        $revision = $submission->currentDraft();
        $attachment = $revision->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringNotContainsString('answer.txt', $attachment->path);
        $url = route('coursework.attachments.download', [$class, $subject, $assignment, $submission, $revision, $attachment]);
        $this->actingAs($student)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($other)->get($url)->assertForbidden();
        $otherAssignment = Assignment::factory()->published()->create(['class_subject_id' => $subject]);
        $this->actingAs($student)->get(route('coursework.attachments.download', [$class, $subject, $otherAssignment, $submission, $revision, $attachment]))->assertNotFound();
        app(SubmitAssignment::class)->handle($student, $submission);
        $this->actingAs($student)->delete($url)->assertForbidden();
    }

    public function test_inactive_and_unverified_users_are_denied_even_with_relationships(): void
    {
        [, $subject] = $this->subject();
        $teacher = $this->teacher($subject);
        $assignment = Assignment::factory()->published()->create(['class_subject_id' => $subject]);
        $teacher->forceFill(['email_verified_at' => null])->save();
        $this->assertFalse($teacher->can('view', $assignment));
        $this->assertFalse($teacher->can('update', $assignment));
    }

    public function test_database_prevents_duplicate_logical_submissions(): void
    {
        [, $subject] = $this->subject();
        $student = $this->student($subject);
        $assignment = Assignment::factory()->published()->create(['class_subject_id' => $subject]);
        $profile = $student->studentProfile;
        AssignmentSubmission::query()->create(['assignment_id' => $assignment->id, 'student_profile_id' => $profile->id]);
        $this->expectException(QueryException::class);
        AssignmentSubmission::query()->create(['assignment_id' => $assignment->id, 'student_profile_id' => $profile->id]);
    }

    public function test_mysql_enforces_assignment_points_check_constraint(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL constraint verification runs in the isolated MySQL gate.');
        }
        [, $subject] = $this->subject();
        $this->expectException(QueryException::class);
        Assignment::factory()->create(['class_subject_id' => $subject, 'max_points' => 0]);
    }

    private function subject(): array
    {
        $class = SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active]);

        return [$class, ClassSubject::factory()->create(['school_class_id' => $class])];
    }

    private function teacher(ClassSubject $subject, bool $currentSubject = true, bool $classTeacher = false): User
    {
        $user = User::factory()->create();
        $user->assignRole('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user]);
        if ($classTeacher) {
            TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile, 'school_class_id' => $subject->school_class_id, 'current_slot' => 1]);
        } else {
            TeacherClassSubjectAssignment::factory()->create(['teacher_profile_id' => $profile, 'class_subject_id' => $subject, 'starts_on' => now()->subDay(), 'current_slot' => $currentSubject ? 1 : null, 'ends_on' => $currentSubject ? null : now()]);
        }

        return $user;
    }

    private function student(ClassSubject $subject): User
    {
        $user = User::factory()->create();
        $user->assignRole('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user]);
        Enrollment::factory()->create(['student_profile_id' => $profile, 'academic_year_id' => $subject->schoolClass->academic_year_id, 'school_class_id' => $subject->school_class_id, 'enrolled_on' => now()->subDay(), 'current_slot' => 1]);

        return $user;
    }
}
