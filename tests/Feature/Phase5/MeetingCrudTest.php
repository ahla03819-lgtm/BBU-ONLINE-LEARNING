<?php

namespace Tests\Feature\Phase5;

use App\Enums\AcademicYearStatus;
use App\Enums\ClassSubjectStatus;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeetingCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_class_teacher_creates_general_meeting_as_host_with_server_defaults(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $payload = $this->payload();
        unset($payload['max_participants']);
        $this->actingAs($teacher)->post(route('meetings.store', $class), $payload)->assertRedirect();

        $meeting = Meeting::query()->sole();
        $this->assertNull($meeting->class_subject_id);
        $this->assertSame($teacher->id, $meeting->host_user_id);
        $this->assertSame($teacher->id, $meeting->created_by);
        $this->assertSame(MeetingStatus::Scheduled, $meeting->status);
        $this->assertSame(50, $meeting->max_participants);
        $this->assertNotEmpty($meeting->uuid);
        $this->assertMatchesRegularExpression('/^edway_[A-Za-z0-9]{40}$/', $meeting->livekit_room_name);
    }

    public function test_subject_teacher_creates_only_their_exact_subject_meeting(): void
    {
        $class = $this->activeClass();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $other = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $teacher = $this->subjectTeacher($subject);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload(['class_subject_id' => $subject->id, 'max_participants' => 75]))->assertRedirect();
        $meeting = Meeting::query()->sole();
        $this->assertSame($subject->id, $meeting->class_subject_id);
        $this->assertSame($teacher->id, $meeting->host_user_id);
        $this->assertSame(75, $meeting->max_participants);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload())->assertForbidden();
        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload(['class_subject_id' => $other->id]))->assertForbidden();
    }

    public function test_admin_must_select_an_eligible_teacher_and_cannot_silently_self_host(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);
        $admin = $this->roleUser('Admin');
        $unassigned = $this->roleUser('Teacher');

        $this->actingAs($admin)->post(route('meetings.store', $class), $this->payload())->assertSessionHasErrors('host_user_id');
        $this->actingAs($admin)->post(route('meetings.store', $class), $this->payload(['host_user_id' => $admin->id]))->assertSessionHasErrors('host_user_id');
        $this->actingAs($admin)->post(route('meetings.store', $class), $this->payload(['host_user_id' => $unassigned->id]))->assertSessionHasErrors('host_user_id');
        $this->actingAs($admin)->post(route('meetings.store', $class), $this->payload(['host_user_id' => $teacher->id]))->assertRedirect();

        $meeting = Meeting::query()->sole();
        $this->assertSame($admin->id, $meeting->created_by);
        $this->assertSame($teacher->id, $meeting->host_user_id);
    }

    public function test_students_historical_teachers_and_inactive_scopes_cannot_create(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $historical = $this->classTeacher($class, false);

        $this->actingAs($student)->post(route('meetings.store', $class), $this->payload())->assertForbidden();
        $this->actingAs($historical)->post(route('meetings.store', $class), $this->payload())->assertForbidden();

        $teacher = $this->classTeacher($class);
        $class->update(['status' => SchoolClassStatus::Closed]);
        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload())->assertForbidden();
        $class->update(['status' => SchoolClassStatus::Active]);
        $class->academicYear->update(['status' => AcademicYearStatus::Closed, 'active_slot' => null]);
        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload())->assertForbidden();
    }

    public function test_inactive_or_cross_class_subject_is_rejected(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $teacher = $this->classTeacher($class);
        $inactive = ClassSubject::factory()->create(['school_class_id' => $class->id, 'status' => ClassSubjectStatus::Archived]);
        $crossClass = ClassSubject::factory()->create(['school_class_id' => $otherClass->id]);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload(['class_subject_id' => $inactive->id]))->assertForbidden();
        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload(['class_subject_id' => $crossClass->id]))->assertSessionHasErrors('class_subject_id');
    }

    public function test_schedule_capacity_and_technical_field_validation_is_strict(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload(['scheduled_end_at' => '2026-09-01 09:00:00']))->assertSessionHasErrors('scheduled_end_at');
        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload(['max_participants' => 1]))->assertSessionHasErrors('max_participants');
        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload(['max_participants' => 501]))->assertSessionHasErrors('max_participants');
        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload(['uuid' => 'client-value', 'livekit_room_name' => 'client-room']))->assertSessionHasErrors(['uuid', 'livekit_room_name']);
        $this->actingAs($teacher)->post(route('meetings.store', $class), $this->payload(['session_started_at' => now()->toIso8601String()]))->assertSessionHasErrors('session_started_at');
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_authorized_scheduled_update_revalidates_host_and_preserves_technical_fields(): void
    {
        $class = $this->activeClass();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $classTeacher = $this->classTeacher($class);
        $subjectTeacher = $this->subjectTeacher($subject);
        $admin = $this->roleUser('Admin');
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $subject->id, 'host_user_id' => $classTeacher->id]);
        $technical = $meeting->only('uuid', 'livekit_room_name', 'lifecycle_version', 'status');

        $this->actingAs($admin)->patch(route('meetings.update', [$class, $meeting]), $this->payload([
            'title' => 'Updated meeting', 'class_subject_id' => $subject->id, 'host_user_id' => $subjectTeacher->id,
        ]))->assertRedirect();

        $meeting->refresh();
        $this->assertSame('Updated meeting', $meeting->title);
        $this->assertSame($subjectTeacher->id, $meeting->host_user_id);
        $this->assertSame($technical, $meeting->only('uuid', 'livekit_room_name', 'lifecycle_version', 'status'));
        $this->actingAs($admin)->patch(route('meetings.update', [$class, $meeting]), $this->payload(['class_subject_id' => $subject->id, 'host_user_id' => $admin->id]))->assertSessionHasErrors('host_user_id');
        $this->actingAs($admin)->patch(route('meetings.update', [$class, $meeting]), $this->payload([
            'class_subject_id' => $subject->id,
            'host_user_id' => $subjectTeacher->id,
            'uuid' => 'client-uuid',
            'livekit_room_name' => 'client-room',
            'status' => MeetingStatus::Cancelled->value,
            'lifecycle_version' => 999,
            'session_started_at' => now()->toIso8601String(),
        ]))->assertSessionHasErrors(['uuid', 'livekit_room_name', 'status', 'lifecycle_version', 'session_started_at']);
        $this->assertSame($technical, $meeting->fresh()->only('uuid', 'livekit_room_name', 'lifecycle_version', 'status'));
    }

    public function test_non_scheduled_and_cross_class_updates_are_denied(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $teacher = $this->classTeacher($class);
        $active = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);
        $scheduled = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);
        $crossSubject = ClassSubject::factory()->create(['school_class_id' => $otherClass->id]);

        $this->actingAs($teacher)->patch(route('meetings.update', [$class, $active]), $this->payload())->assertForbidden();
        $this->actingAs($teacher)->patch(route('meetings.update', [$otherClass, $scheduled]), $this->payload())->assertNotFound();
        $this->actingAs($teacher)->patch(route('meetings.update', [$class, $scheduled]), $this->payload(['class_subject_id' => $crossSubject->id]))->assertSessionHasErrors('class_subject_id');
    }

    public function test_cancel_is_history_preserving_versioned_and_idempotent(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id, 'lifecycle_version' => 4]);
        $url = route('meetings.cancel', [$class, $meeting]);

        $this->actingAs($teacher)->patch($url)->assertRedirect();
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id, 'status' => MeetingStatus::Cancelled->value, 'lifecycle_version' => 5]);
        $this->actingAs($teacher)->patch($url)->assertRedirect();
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id, 'lifecycle_version' => 5]);
        $this->assertSame(1, AuditLog::query()->where('action', 'meeting.cancelled')->count());
    }

    public function test_invalid_or_unauthorized_cancellation_is_denied(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);
        $student = $this->student($class);
        $active = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);
        $scheduled = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);

        $this->actingAs($teacher)->patch(route('meetings.cancel', [$class, $active]))->assertForbidden();
        $this->actingAs($student)->patch(route('meetings.cancel', [$class, $scheduled]))->assertForbidden();
    }

    public function test_list_and_detail_are_relationship_scoped_and_pages_resolve(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $teacher = $this->classTeacher($class);
        $visible = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $teacher->id]);
        $hidden = Meeting::factory()->create(['school_class_id' => $otherClass->id]);

        $this->actingAs($teacher)->get(route('meetings.index', $class))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Meetings/Index')
            ->has('meetings', 1)
            ->where('meetings.0.uuid', $visible->uuid)
            ->where('canCreate', true));
        $this->actingAs($teacher)->get(route('meetings.show', [$class, $visible]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Meetings/Show')->where('meeting.uuid', $visible->uuid)->missing('meeting.livekit_room_name'));
        $this->actingAs($teacher)->get(route('meetings.show', [$class, $hidden]))->assertNotFound();
        $this->actingAs($teacher)->get(route('meetings.create', $class))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Meetings/Create'));
        $this->actingAs($teacher)->get(route('meetings.edit', [$class, $visible]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Meetings/Edit'));
    }

    public function test_subject_teacher_and_student_lists_are_exactly_scoped(): void
    {
        $class = $this->activeClass();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $otherSubject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $subjectTeacher = $this->subjectTeacher($subject);
        $student = $this->student($class);
        Meeting::factory()->create(['school_class_id' => $class->id]);
        Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $subject->id, 'host_user_id' => $subjectTeacher->id]);
        Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $otherSubject->id]);

        $this->actingAs($subjectTeacher)->get(route('meetings.index', $class))->assertInertia(fn (Assert $page) => $page->has('meetings', 1)->where('meetings.0.class_subject_id', $subject->id));
        $this->actingAs($student)->get(route('meetings.index', $class))->assertInertia(fn (Assert $page) => $page->has('meetings', 3)->where('canCreate', false));

        Enrollment::query()->whereHas('studentProfile', fn ($query) => $query->where('user_id', $student->id))->update(['current_slot' => null, 'ended_on' => now()]);
        $this->actingAs($student)->get(route('meetings.index', $class))->assertForbidden();
    }

    public function test_admin_and_super_admin_have_broad_valid_nested_scope(): void
    {
        $class = $this->activeClass();
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id]);
        $class->update(['status' => SchoolClassStatus::Closed]);

        foreach (['Admin', 'Super Admin'] as $role) {
            $this->actingAs($this->roleUser($role))->get(route('meetings.index', $class))->assertOk()->assertInertia(fn (Assert $page) => $page->has('meetings', 1)->where('meetings.0.uuid', $meeting->uuid));
        }
    }

    public function test_audit_events_are_safe_and_cover_create_update_host_change_and_cancel(): void
    {
        $class = $this->activeClass();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $classTeacher = $this->classTeacher($class);
        $subjectTeacher = $this->subjectTeacher($subject);
        $admin = $this->roleUser('Admin');
        $this->actingAs($admin)->post(route('meetings.store', $class), $this->payload(['class_subject_id' => $subject->id, 'host_user_id' => $classTeacher->id]));
        $meeting = Meeting::query()->sole();
        $this->actingAs($admin)->patch(route('meetings.update', [$class, $meeting]), $this->payload(['title' => 'Audited update', 'class_subject_id' => $subject->id, 'host_user_id' => $subjectTeacher->id]));
        $this->actingAs($admin)->patch(route('meetings.cancel', [$class, $meeting]));

        foreach (['meeting.created', 'meeting.host-assigned', 'meeting.updated', 'meeting.host-changed', 'meeting.cancelled'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['action' => $event, 'target_id' => $meeting->id]);
        }
        AuditLog::query()->where('target_id', $meeting->id)->each(function (AuditLog $log) use ($meeting) {
            $metadata = json_encode([$log->before, $log->after]);
            $this->assertStringNotContainsString('livekit_room_name', $metadata);
            $this->assertStringNotContainsString($meeting->livekit_room_name, $metadata);
        });
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Scheduled lesson',
            'description' => 'Planning details',
            'scheduled_start_at' => '2026-09-01 10:00:00',
            'scheduled_end_at' => '2026-09-01 11:00:00',
            'max_participants' => 50,
        ], $overrides);
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active]);
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
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id, 'current_slot' => $current ? 1 : null, 'ends_on' => $current ? null : now()]);

        return $user;
    }

    private function subjectTeacher(ClassSubject $subject): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassSubjectAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'class_subject_id' => $subject->id]);

        return $user;
    }

    private function student(SchoolClass $class): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create(['student_profile_id' => $profile->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id]);

        return $user;
    }
}
