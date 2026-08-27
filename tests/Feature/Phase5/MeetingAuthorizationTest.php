<?php

namespace Tests\Feature\Phase5;

use App\Enums\AcademicYearStatus;
use App\Enums\AccountStatus;
use App\Enums\ClassSubjectStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
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
use App\Policies\MeetingParticipantPolicy;
use App\Policies\MeetingPolicy;
use App\Services\MeetingAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MeetingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'meetings.view',
        'meetings.create',
        'meetings.update',
        'meetings.cancel',
        'meetings.start',
        'meetings.end',
        'meetings.join',
        'meetings.participants.view',
        'meetings.participants.remove',
        'meetings.tokens.issue',
        'meetings.screen-share',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_phase_five_permissions_and_role_grants_are_exact(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission]);
            $this->assertTrue(Role::findByName('Super Admin')->hasPermissionTo($permission));
            $this->assertTrue(Role::findByName('Admin')->hasPermissionTo($permission));
            $this->assertTrue(Role::findByName('Teacher')->hasPermissionTo($permission));
        }

        $student = Role::findByName('Student');
        foreach (['meetings.view', 'meetings.join', 'meetings.participants.view', 'meetings.tokens.issue'] as $permission) {
            $this->assertTrue($student->hasPermissionTo($permission));
        }
        foreach (array_diff(self::PERMISSIONS, ['meetings.view', 'meetings.join', 'meetings.participants.view', 'meetings.tokens.issue']) as $permission) {
            $this->assertFalse($student->hasPermissionTo($permission));
        }
    }

    public function test_laravel_discovers_both_meeting_policies(): void
    {
        $this->assertInstanceOf(MeetingPolicy::class, Gate::getPolicyFor(Meeting::class));
        $this->assertInstanceOf(MeetingParticipantPolicy::class, Gate::getPolicyFor(MeetingParticipant::class));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(count(self::PERMISSIONS), Permission::query()->whereIn('name', self::PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('Teacher')->hasAllPermissions(self::PERMISSIONS));
    }

    public function test_coarse_meetings_host_permission_authorizes_no_phase_five_ability(): void
    {
        $class = $this->activeClass();
        $user = User::factory()->create();
        $user->givePermissionTo('meetings.host');
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        $policy = $this->policy();

        $this->assertFalse($policy->view($user, $meeting));
        $this->assertFalse($policy->create($user, $class));
        $this->assertFalse($policy->update($user, $meeting));
        $this->assertFalse($policy->join($user, $meeting));
        $this->assertFalse($policy->issueToken($user, $meeting));
    }

    public function test_current_class_teacher_can_manage_general_and_subject_meetings(): void
    {
        $class = $this->activeClass();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id, 'status' => ClassSubjectStatus::Active]);
        $teacher = $this->classTeacher($class);
        $general = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);
        $subjectMeeting = Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $subject->id, 'host_user_id' => $teacher->id]);
        $policy = $this->policy();

        $this->assertTrue($policy->create($teacher, $class));
        $this->assertTrue($policy->create($teacher, $class, $subject));
        $this->assertTrue($policy->view($teacher, $general));
        $this->assertTrue($policy->update($teacher, $subjectMeeting));
        $this->assertTrue($policy->cancel($teacher, $general));
        $this->assertTrue($policy->start($teacher, $general));
        $this->assertTrue($policy->viewParticipants($teacher, $general));
    }

    public function test_historical_class_teacher_and_unassigned_teacher_are_denied(): void
    {
        $class = $this->activeClass();
        $historical = $this->classTeacher($class, false);
        $unassigned = $this->roleUser('Teacher');
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id]);

        foreach ([$historical, $unassigned] as $teacher) {
            $this->assertFalse($this->policy()->view($teacher, $meeting));
            $this->assertFalse($this->policy()->create($teacher, $class));
            $this->assertFalse($this->policy()->update($teacher, $meeting));
        }
    }

    public function test_current_subject_teacher_is_limited_to_exact_active_subject(): void
    {
        $class = $this->activeClass();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id, 'status' => ClassSubjectStatus::Active]);
        $otherSubject = ClassSubject::factory()->create(['school_class_id' => $class->id, 'status' => ClassSubjectStatus::Active]);
        $teacher = $this->subjectTeacher($subject);
        $exact = Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $subject->id, 'host_user_id' => $teacher->id]);
        $crossSubject = Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $otherSubject->id]);
        $general = Meeting::factory()->create(['school_class_id' => $class->id]);

        $this->assertTrue($this->policy()->create($teacher, $class, $subject));
        $this->assertTrue($this->policy()->view($teacher, $exact));
        $this->assertTrue($this->policy()->update($teacher, $exact));
        $this->assertFalse($this->policy()->create($teacher, $class, $otherSubject));
        $this->assertFalse($this->policy()->view($teacher, $crossSubject));
        $this->assertFalse($this->policy()->update($teacher, $general));
    }

    public function test_historical_subject_teacher_is_denied(): void
    {
        $class = $this->activeClass();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $teacher = $this->subjectTeacher($subject, false);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $subject->id]);

        $this->assertFalse($this->policy()->view($teacher, $meeting));
        $this->assertFalse($this->policy()->create($teacher, $class, $subject));
    }

    public function test_current_student_has_class_scoped_least_privilege(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        $otherMeeting = Meeting::factory()->active()->create(['school_class_id' => $otherClass->id]);
        $participant = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id]);
        $policy = $this->policy();

        $this->assertTrue($policy->view($student, $meeting));
        $this->assertTrue($policy->join($student, $meeting));
        $this->assertTrue($policy->issueToken($student, $meeting));
        $this->assertTrue($policy->viewParticipants($student, $meeting));
        $this->assertTrue(app(MeetingParticipantPolicy::class)->view($student, $participant));
        $this->assertFalse($policy->view($student, $otherMeeting));
        $this->assertFalse($policy->create($student, $class));
        $this->assertFalse($policy->update($student, $meeting));
        $this->assertFalse($policy->cancel($student, $meeting));
        $this->assertFalse($policy->start($student, $meeting));
        $this->assertFalse($policy->end($student, $meeting));
        $this->assertFalse($policy->removeParticipant($student, $meeting, $participant));
    }

    public function test_historical_student_and_removed_participant_are_denied_operational_access(): void
    {
        $class = $this->activeClass();
        $historical = $this->student($class, false);
        $current = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $current->id, 'removed_at' => now()]);

        $this->assertFalse($this->policy()->view($historical, $meeting));
        $this->assertFalse($this->policy()->join($historical, $meeting));
        $this->assertTrue($this->policy()->view($current, $meeting));
        $this->assertFalse($this->policy()->join($current, $meeting));
        $this->assertFalse($this->policy()->issueToken($current, $meeting));
    }

    public function test_inactive_academic_scope_denies_teacher_and_student_access(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);
        $student = $this->student($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id]);

        $class->update(['status' => SchoolClassStatus::Closed]);
        $this->assertFalse($this->policy()->view($teacher, $meeting));
        $this->assertFalse($this->policy()->view($student, $meeting));

        $class->update(['status' => SchoolClassStatus::Active]);
        $class->academicYear->update(['status' => AcademicYearStatus::Closed, 'active_slot' => null]);
        $this->assertFalse($this->policy()->view($teacher, $meeting));
        $this->assertFalse($this->policy()->view($student, $meeting));
    }

    public function test_inactive_subject_denies_subject_meeting_access(): void
    {
        $class = $this->activeClass();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $teacher = $this->subjectTeacher($subject);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $subject->id]);
        $subject->update(['status' => ClassSubjectStatus::Archived]);

        $this->assertFalse($this->policy()->view($teacher, $meeting));
        $this->assertFalse($this->policy()->update($teacher, $meeting));
    }

    public function test_nested_class_subject_mismatch_is_denied_even_to_administrators(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $subject = ClassSubject::factory()->create(['school_class_id' => $otherClass->id]);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $subject->id]);

        $this->assertFalse($this->policy()->view($this->roleUser('Admin'), $meeting));
        $superAdmin = $this->roleUser('Super Admin');
        $this->assertFalse($this->policy()->view($superAdmin, $meeting));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('view', $meeting));
    }

    public function test_admin_management_is_permissioned_but_host_operations_require_eligible_assignment(): void
    {
        $class = $this->activeClass();
        $admin = $this->roleUser('Admin');
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $admin->id]);

        $this->assertTrue($this->policy()->view($admin, $meeting));
        $this->assertTrue($this->policy()->create($admin, $class));
        $this->assertTrue($this->policy()->update($admin, $meeting));
        $this->assertTrue($this->policy()->cancel($admin, $meeting));
        $this->assertFalse(app(MeetingAccess::class)->isEligibleHost($admin, $meeting));
        $this->assertFalse($this->policy()->start($admin, $meeting));
    }

    public function test_super_admin_override_is_explicit_but_never_bypasses_nested_scope(): void
    {
        $class = $this->activeClass();
        $superAdmin = $this->roleUser('Super Admin');
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id]);

        $this->assertTrue($this->policy()->view($superAdmin, $meeting));
        $this->assertTrue($this->policy()->create($superAdmin, $class));
        $this->assertTrue($this->policy()->update($superAdmin, $meeting));
        $this->assertTrue($this->policy()->start($superAdmin, $meeting));
    }

    public function test_host_can_remove_only_active_nested_nonself_participant(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);
        $participant = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id]);
        $otherMeetingParticipant = MeetingParticipant::factory()->create();
        $self = MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $host->id]);

        $this->assertTrue($this->policy()->removeParticipant($host, $meeting, $participant));
        $this->assertTrue(app(MeetingParticipantPolicy::class)->remove($host, $participant));
        $this->assertFalse($this->policy()->removeParticipant($host, $meeting, $otherMeetingParticipant));
        $this->assertFalse($this->policy()->removeParticipant($host, $meeting, $self));
        $participant->update(['removed_at' => now()]);
        $this->assertFalse($this->policy()->removeParticipant($host, $meeting, $participant));
    }

    public function test_inactive_or_unverified_accounts_are_always_denied(): void
    {
        $class = $this->activeClass();
        $inactive = $this->classTeacher($class);
        $inactive->update(['status' => AccountStatus::Inactive]);
        $otherClass = SchoolClass::factory()->create([
            'academic_year_id' => $class->academic_year_id,
            'status' => SchoolClassStatus::Active,
        ]);
        $unverified = $this->classTeacher($otherClass);
        $unverified->forceFill(['email_verified_at' => null])->save();
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id]);
        $otherMeeting = Meeting::factory()->create(['school_class_id' => $otherClass->id]);

        $this->assertFalse($this->policy()->view($inactive, $meeting));
        $this->assertFalse($this->policy()->create($inactive, $class));
        $this->assertFalse($this->policy()->view($unverified, $otherMeeting));
        $this->assertFalse($this->policy()->create($unverified, $otherClass));
    }

    private function policy(): MeetingPolicy
    {
        return app(MeetingPolicy::class);
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create([
            'academic_year_id' => AcademicYear::factory()->active(),
            'status' => SchoolClassStatus::Active,
        ]);
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function classTeacher(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'school_class_id' => $class->id,
            'current_slot' => $current ? 1 : null,
            'ends_on' => $current ? null : now()->toDateString(),
        ]);

        return $user;
    }

    private function subjectTeacher(ClassSubject $subject, bool $current = true): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassSubjectAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'class_subject_id' => $subject->id,
            'current_slot' => $current ? 1 : null,
            'ends_on' => $current ? null : now()->toDateString(),
        ]);

        return $user;
    }

    private function student(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
            'current_slot' => $current ? 1 : null,
            'ended_on' => $current ? null : now()->toDateString(),
        ]);

        return $user;
    }
}
