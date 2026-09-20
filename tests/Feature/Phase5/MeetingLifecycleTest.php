<?php

namespace Tests\Feature\Phase5;

use App\Actions\Meetings\EndMeeting;
use App\Actions\Meetings\ReconcileMeetingLifecycle;
use App\Actions\Meetings\StartMeeting;
use App\Contracts\MeetingLifecycleProvider;
use App\Enums\AcademicYearStatus;
use App\Enums\AccountStatus;
use App\Enums\ClassSubjectStatus;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Policies\MeetingPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeMeetingLifecycleProvider;
use Tests\TestCase;

class MeetingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FakeMeetingLifecycleProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->provider = new FakeMeetingLifecycleProvider;
        $this->app->instance(MeetingLifecycleProvider::class, $this->provider);
    }

    public function test_class_teacher_start_moves_scheduled_through_starting_to_active_once(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'lifecycle_version' => 3]);

        $result = $this->actingAs($host)->app->make(StartMeeting::class)->handle($host, $meeting);

        $this->assertSame(MeetingStatus::Active, $result->status);
        $this->assertSame(5, $result->lifecycle_version);
        $this->assertNotNull($result->actual_start_at);
        $this->assertTrue(Str::isUuid($result->start_attempt_uuid));
        $this->assertSame(1, $this->provider->startCalls);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.start-requested', 'target_id' => $meeting->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.start-succeeded', 'target_id' => $meeting->id]);

        $again = app(StartMeeting::class)->handle($host, $result);
        $this->assertSame(5, $again->lifecycle_version);
        $this->assertSame(1, $this->provider->startCalls);
    }

    public function test_subject_teacher_can_start_only_exact_subject_meeting(): void
    {
        $class = $this->activeClass();
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $host = $this->subjectTeacher($subject);
        $exact = Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $subject->id, 'host_user_id' => $host->id]);
        $general = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);

        $this->actingAs($host)->post(route('meetings.start', [$class, $exact]))->assertRedirect();
        $this->assertSame(MeetingStatus::Active, $exact->fresh()->status);
        $this->actingAs($host)->post(route('meetings.start', [$class, $general]))->assertForbidden();
    }

    public function test_student_unassigned_and_historical_teachers_cannot_start_or_end(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $student = $this->roleUser('Student');
        $unassigned = $this->roleUser('Teacher');
        $historical = $this->classTeacher($class, false);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);

        foreach ([$student, $unassigned, $historical] as $user) {
            $this->actingAs($user)->post(route('meetings.start', [$class, $meeting]))->assertForbidden();
        }
        $meeting->update(['status' => MeetingStatus::Active]);
        $this->actingAs($student)->post(route('meetings.end', [$class, $meeting]))->assertForbidden();
        $this->actingAs($unassigned)->post(route('meetings.end', [$class, $meeting]))->assertForbidden();
    }

    public function test_start_revalidates_active_class_year_subject_and_assigned_host(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);

        $class->update(['status' => SchoolClassStatus::Closed]);
        $this->actingAs($host)->post(route('meetings.start', [$class, $meeting]))->assertForbidden();
        $class->update(['status' => SchoolClassStatus::Active]);
        $class->academicYear->update(['status' => AcademicYearStatus::Closed, 'active_slot' => null]);
        $this->actingAs($host)->post(route('meetings.start', [$class, $meeting]))->assertForbidden();

        $class->academicYear->update(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);
        $subject = ClassSubject::factory()->create(['school_class_id' => $class->id]);
        $subjectMeeting = Meeting::factory()->create(['school_class_id' => $class->id, 'class_subject_id' => $subject->id, 'host_user_id' => $host->id]);
        $subject->update(['status' => ClassSubjectStatus::Archived]);
        $this->actingAs($host)->post(route('meetings.start', [$class, $subjectMeeting]))->assertForbidden();

        $otherTeacher = $this->roleUser('Teacher');
        $meeting->update(['host_user_id' => $otherTeacher->id]);
        $this->actingAs($host)->post(route('meetings.start', [$class, $meeting]))->assertForbidden();
    }

    public function test_inactive_and_unverified_hosts_cannot_start(): void
    {
        $class = $this->activeClass();
        $inactive = $this->classTeacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $inactive->id]);
        $inactive->update(['status' => AccountStatus::Inactive]);
        $this->assertFalse(app(MeetingPolicy::class)->start($inactive, $meeting));

        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $unverified = $this->classTeacher($otherClass);
        $otherMeeting = Meeting::factory()->create(['school_class_id' => $otherClass->id, 'host_user_id' => $unverified->id]);
        $unverified->forceFill(['email_verified_at' => null])->save();
        $this->assertFalse(app(MeetingPolicy::class)->start($unverified, $otherMeeting));
    }

    public function test_overlapping_start_requests_invoke_provider_only_once(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);
        $overlap = null;
        $this->provider->onStart = function () use ($host, $meeting, &$overlap) {
            $overlap = app(StartMeeting::class)->handle($host, $meeting->fresh());
        };

        $result = $this->actingAs($host)->app->make(StartMeeting::class)->handle($host, $meeting);

        $this->assertSame(MeetingStatus::Starting, $overlap->status);
        $this->assertSame(MeetingStatus::Active, $result->status);
        $this->assertSame(1, $this->provider->startCalls);
    }

    public function test_stale_start_attempt_cannot_activate_newer_state(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $attempt = (string) Str::uuid();
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Starting, 'start_attempt_uuid' => $attempt, 'lifecycle_version' => 4]);

        $result = app(StartMeeting::class)->complete($meeting, (string) Str::uuid());

        $this->assertSame(MeetingStatus::Starting, $result->status);
        $this->assertSame(4, $result->lifecycle_version);
        $this->assertNull($result->actual_start_at);
    }

    public function test_cancelled_and_ended_meetings_cannot_start_and_cancelled_cannot_end(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $cancelled = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Cancelled]);
        $ended = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Ended]);

        $this->actingAs($host)->post(route('meetings.start', [$class, $cancelled]))->assertForbidden();
        $this->actingAs($host)->post(route('meetings.start', [$class, $ended]))->assertForbidden();
        $this->actingAs($host)->post(route('meetings.end', [$class, $cancelled]))->assertForbidden();
    }

    public function test_host_end_moves_active_through_ending_to_ended_once(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'lifecycle_version' => 7]);

        $result = $this->actingAs($host)->app->make(EndMeeting::class)->handle($host, $meeting);

        $this->assertSame(MeetingStatus::Ended, $result->status);
        $this->assertSame(9, $result->lifecycle_version);
        $this->assertNotNull($result->actual_end_at);
        $this->assertSame(1, $this->provider->endCalls);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.end-requested', 'target_id' => $meeting->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.end-succeeded', 'target_id' => $meeting->id]);

        $again = app(EndMeeting::class)->handle($host, $result);
        $this->assertSame(9, $again->lifecycle_version);
        $this->assertSame(1, $this->provider->endCalls);
    }

    public function test_authorized_json_end_returns_lifecycle_state_without_a_room_redirect(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);

        $this->actingAs($host)->postJson(route('meetings.end', [$class, $meeting]))
            ->assertOk()
            ->assertHeaderMissing('Location')
            ->assertJsonPath('meeting.uuid', $meeting->uuid)
            ->assertJsonPath('meeting.status', MeetingStatus::Ended->value)
            ->assertJsonPath('meeting.lifecycle_version', 3);

        $this->assertSame(MeetingStatus::Ended, $meeting->fresh()->status);
    }

    public function test_stale_end_completion_cannot_overwrite_newer_version(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Ending, 'lifecycle_version' => 5]);

        $result = app(EndMeeting::class)->complete($meeting, 4);

        $this->assertSame(MeetingStatus::Ending, $result->status);
        $this->assertSame(5, $result->lifecycle_version);
        $this->assertNull($result->actual_end_at);
    }

    public function test_provider_failures_are_sanitized_and_leave_reconcilable_state(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);
        $this->provider->failStart = true;

        $result = $this->actingAs($host)->app->make(StartMeeting::class)->handle($host, $meeting);

        $this->assertSame(MeetingStatus::Starting, $result->status);
        $this->assertSame('Meeting provider start failed.', $result->last_provider_error);
        $this->assertStringNotContainsString('secret-provider-detail', $result->last_provider_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.start-failed', 'target_id' => $meeting->id]);
    }

    public function test_unknown_start_state_records_only_a_generic_error_and_remains_reconcilable(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);
        $this->provider->startState = MeetingProviderState::Unknown;

        $result = $this->actingAs($host)->app->make(StartMeeting::class)->handle($host, $meeting);

        $this->assertSame(MeetingStatus::Starting, $result->status);
        $this->assertSame('Meeting provider start failed.', $result->last_provider_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.start-failed', 'target_id' => $meeting->id]);
    }

    public function test_end_failure_is_sanitized_and_can_be_reconciled_from_known_state(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);
        $this->provider->failEnd = true;

        $result = $this->actingAs($host)->app->make(EndMeeting::class)->handle($host, $meeting);
        $this->assertSame(MeetingStatus::Ending, $result->status);
        $this->assertSame('Meeting provider end failed.', $result->last_provider_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.end-failed', 'target_id' => $meeting->id]);

        $this->provider->inspectState = MeetingProviderState::Ended;
        $recovered = app(ReconcileMeetingLifecycle::class)->handle($result);
        $this->assertSame(MeetingStatus::Ended, $recovered->status);
        $this->assertNull($recovered->last_provider_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.end-recovered', 'target_id' => $meeting->id]);
    }

    public function test_reconciliation_uses_known_provider_state_and_is_idempotent(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $starting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Starting, 'start_attempt_uuid' => (string) Str::uuid(), 'lifecycle_version' => 2]);
        $this->provider->inspectState = MeetingProviderState::Active;

        $dryRun = app(ReconcileMeetingLifecycle::class)->handle($starting, true);
        $this->assertSame(MeetingStatus::Starting, $dryRun->status);
        $recovered = app(ReconcileMeetingLifecycle::class)->handle($starting);
        $this->assertSame(MeetingStatus::Active, $recovered->status);
        $this->assertSame(3, $recovered->lifecycle_version);
        $this->assertNotNull($recovered->actual_start_at);
        $rerun = app(ReconcileMeetingLifecycle::class)->handle($recovered);
        $this->assertSame(3, $rerun->lifecycle_version);
        $this->assertSame(1, AuditLog::query()->where('action', 'meeting.start-recovered')->count());

        $ending = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Ending, 'lifecycle_version' => 8]);
        $this->provider->inspectState = MeetingProviderState::Ended;
        $ended = app(ReconcileMeetingLifecycle::class)->handle($ending);
        $this->assertSame(MeetingStatus::Ended, $ended->status);
        $this->assertSame(9, $ended->lifecycle_version);
        $this->assertNotNull($ended->actual_end_at);
    }

    public function test_unknown_reconciliation_state_never_guesses_or_revives(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Starting, 'start_attempt_uuid' => (string) Str::uuid(), 'lifecycle_version' => 6]);

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $this->assertSame(MeetingStatus::Starting, $result->status);
        $this->assertSame(6, $result->lifecycle_version);
        $this->artisan('meetings:reconcile-lifecycle', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(MeetingStatus::Starting, $meeting->fresh()->status);
    }

    public function test_non_scheduled_states_remain_immutable_through_crud_routes(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        foreach ([MeetingStatus::Starting, MeetingStatus::Active, MeetingStatus::Ending, MeetingStatus::Ended, MeetingStatus::Cancelled] as $status) {
            $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => $status]);
            $this->actingAs($host)->patch(route('meetings.update', [$class, $meeting]), [])->assertForbidden();
        }
        $this->assertSame(5, Meeting::query()->count());
    }

    public function test_routes_are_nested_and_show_serializes_only_valid_lifecycle_controls(): void
    {
        $class = $this->activeClass();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $host = $this->classTeacher($class);
        $scheduled = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);

        $this->actingAs($host)->get(route('meetings.show', [$class, $scheduled]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Meetings/Show')
            ->where('meeting.can_start', true)
            ->where('meeting.can_end', false));
        $this->actingAs($host)->post(route('meetings.start', [$otherClass, $scheduled]))->assertNotFound();
    }

    public function test_lifecycle_audit_metadata_contains_no_provider_secrets(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id]);
        $this->actingAs($host)->app->make(StartMeeting::class)->handle($host, $meeting);
        app(EndMeeting::class)->handle($host, $meeting->fresh());

        AuditLog::query()->where('target_id', $meeting->id)->each(function (AuditLog $log) use ($meeting) {
            $metadata = json_encode([$log->before, $log->after]);
            $this->assertStringNotContainsString('secret', $metadata);
            $this->assertStringNotContainsString($meeting->livekit_room_name, $metadata);
        });
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
}
