<?php

namespace Tests\Feature;

use App\Actions\Meetings\EndMeeting;
use App\Actions\Meetings\ReconcileMeetingLifecycle;
use App\Contracts\MeetingLifecycleProvider;
use App\Enums\AcademicYearStatus;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\FakeMeetingLifecycleProvider;
use Tests\TestCase;

/**
 * The backstop that ends a meeting the provider has already ended.
 *
 * These cover the two properties that matter: it only ever acts on a row the
 * provider has vouched for, and it never touches a row that predates the moment
 * the backstop was switched on.
 */
class StaleActiveMeetingRecoveryTest extends TestCase
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

    public function test_active_meeting_stays_active_while_the_provider_still_has_its_room(): void
    {
        $this->enableRecovery();
        $meeting = $this->activeMeeting();
        $this->provider->inspectState = MeetingProviderState::Active;

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $this->assertSame(MeetingStatus::Active, $result->status);
        $this->assertSame(2, $result->lifecycle_version);
        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.end-recovered')->count());
    }

    public function test_active_meeting_is_ended_when_the_provider_reports_the_room_gone(): void
    {
        $this->enableRecovery();
        $meeting = $this->activeMeeting();
        $this->provider->inspectState = MeetingProviderState::Ended;

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $this->assertSame(MeetingStatus::Ended, $result->status);
        $this->assertSame(3, $result->lifecycle_version);
        $this->assertNull($result->last_provider_error);
    }

    public function test_active_meeting_survives_an_unknown_provider_state(): void
    {
        $this->enableRecovery();
        $meeting = $this->activeMeeting();
        $this->provider->inspectState = MeetingProviderState::Unknown;

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $this->assertSame(MeetingStatus::Active, $result->status);
        $this->assertSame(2, $result->lifecycle_version);
        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.end-recovered')->count());
    }

    public function test_active_recovery_increments_the_lifecycle_version_exactly_once(): void
    {
        $this->enableRecovery();
        $meeting = $this->activeMeeting(lifecycleVersion: 7);
        $this->provider->inspectState = MeetingProviderState::Ended;

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $this->assertSame(8, $result->lifecycle_version);
        $this->assertSame(8, $result->fresh()->lifecycle_version);
    }

    public function test_active_recovery_records_one_recovery_event_that_names_its_own_source(): void
    {
        $this->enableRecovery();
        $meeting = $this->activeMeeting();
        $this->provider->inspectState = MeetingProviderState::Ended;

        app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $recoveries = AuditLog::query()
            ->where('action', 'meeting.end-recovered')
            ->where('target_id', $meeting->id)
            ->get();
        $this->assertCount(1, $recoveries);
        $after = $recoveries->sole()->after;
        $this->assertSame('provider_reconciliation', $after['source']);
        $this->assertSame(MeetingStatus::Active->value, $after['previous_status']);
        $this->assertSame(MeetingProviderState::Ended->value, $after['provider_state']);
        $this->assertFalse($after['exact_end_timestamp_available']);
        $this->assertArrayHasKey('observed_at', $after);

        // It must never be mistaken for a person pressing End for everyone.
        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.end-succeeded')->where('target_id', $meeting->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.end-requested')->where('target_id', $meeting->id)->count());
    }

    /**
     * The provider's inspect() answers whether a room is still there, never when
     * it stopped being there. So this path must not invent an end time, and the
     * attendance report already reports the duration as unavailable rather than
     * deriving a percentage from a timestamp nobody witnessed.
     */
    public function test_active_recovery_never_fabricates_an_end_timestamp(): void
    {
        $this->enableRecovery();
        $meeting = $this->activeMeeting();
        $this->provider->inspectState = MeetingProviderState::Ended;

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $this->assertSame(MeetingStatus::Ended, $result->status);
        $this->assertNull($result->actual_end_at);
        $this->assertNull($result->fresh()->actual_end_at);
    }

    public function test_repeated_reconciliation_is_idempotent(): void
    {
        $this->enableRecovery();
        $meeting = $this->activeMeeting();
        $this->provider->inspectState = MeetingProviderState::Ended;

        $first = app(ReconcileMeetingLifecycle::class)->handle($meeting);
        $second = app(ReconcileMeetingLifecycle::class)->handle($first);
        $third = app(ReconcileMeetingLifecycle::class)->handle($second);

        $this->assertSame(MeetingStatus::Ended, $third->status);
        $this->assertSame(3, $third->lifecycle_version);
        $this->assertSame(1, AuditLog::query()->where('action', 'meeting.end-recovered')->where('target_id', $meeting->id)->count());
    }

    public function test_dry_run_inspects_an_active_meeting_without_ending_it(): void
    {
        $this->enableRecovery();
        $meeting = $this->activeMeeting();
        $this->provider->inspectState = MeetingProviderState::Ended;

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting, true);

        $this->assertSame(MeetingStatus::Active, $result->status);
        $this->assertSame(2, $meeting->fresh()->lifecycle_version);
        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.end-recovered')->count());
    }

    /**
     * A host pressing End for everyone must win, and the backstop must not then
     * end the meeting a second time or overwrite the end the host just made.
     */
    public function test_a_concurrent_human_end_is_never_overwritten(): void
    {
        $this->enableRecovery();
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $meeting = Meeting::factory()->active()->create([
            'school_class_id' => $class->id,
            'host_user_id' => $host->id,
            'lifecycle_version' => 4,
        ]);

        $ended = $this->actingAs($host)->app->make(EndMeeting::class)->handle($host, $meeting);
        $this->assertSame(MeetingStatus::Ended, $ended->status);
        $this->assertNotNull($ended->actual_end_at);
        $hostEnd = $ended->actual_end_at;

        // The backstop arrives afterwards holding a version it read beforehand.
        $this->provider->inspectState = MeetingProviderState::Ended;
        $stale = app(ReconcileMeetingLifecycle::class)->handle($ended, false, 4);

        $this->assertSame(MeetingStatus::Ended, $stale->status);
        $this->assertSame(6, $stale->lifecycle_version);
        $this->assertTrue($hostEnd->equalTo($stale->actual_end_at));
        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.end-recovered')->where('target_id', $meeting->id)->count());
    }

    public function test_starting_reconciliation_is_unchanged(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $starting = Meeting::factory()->create([
            'school_class_id' => $class->id,
            'host_user_id' => $host->id,
            'status' => MeetingStatus::Starting,
            'start_attempt_uuid' => (string) Str::uuid(),
            'lifecycle_version' => 2,
        ]);
        $this->provider->inspectState = MeetingProviderState::Active;

        $recovered = app(ReconcileMeetingLifecycle::class)->handle($starting);

        $this->assertSame(MeetingStatus::Active, $recovered->status);
        $this->assertSame(3, $recovered->lifecycle_version);
        $this->assertNotNull($recovered->actual_start_at);
        $this->assertNotNull($recovered->session_started_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.start-recovered', 'target_id' => $starting->id]);
    }

    public function test_ending_reconciliation_is_unchanged(): void
    {
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $ending = Meeting::factory()->create([
            'school_class_id' => $class->id,
            'host_user_id' => $host->id,
            'status' => MeetingStatus::Ending,
            'lifecycle_version' => 8,
        ]);
        $this->provider->inspectState = MeetingProviderState::Ended;

        $recovered = app(ReconcileMeetingLifecycle::class)->handle($ending);

        $this->assertSame(MeetingStatus::Ended, $recovered->status);
        $this->assertSame(9, $recovered->lifecycle_version);
        $this->assertNotNull($recovered->actual_end_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.end-recovered', 'target_id' => $ending->id]);
    }

    public function test_scheduled_ended_and_cancelled_meetings_are_never_recovered(): void
    {
        $this->enableRecovery();
        $class = $this->activeClass();
        $host = $this->classTeacher($class);
        $this->provider->inspectState = MeetingProviderState::Ended;

        foreach ([MeetingStatus::Scheduled, MeetingStatus::Ended, MeetingStatus::Cancelled] as $status) {
            $meeting = Meeting::factory()->create([
                'school_class_id' => $class->id,
                'host_user_id' => $host->id,
                'status' => $status,
                'actual_start_at' => now(),
                'lifecycle_version' => 3,
            ]);

            $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

            $this->assertSame($status, $result->status, "A {$status->value} meeting must be left alone.");
            $this->assertSame(3, $result->lifecycle_version);
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.end-recovered')->count());
    }

    public function test_recovery_is_disabled_by_default(): void
    {
        config(['meetings.active_recovery.enabled' => false]);
        $meeting = $this->activeMeeting();
        $this->provider->inspectState = MeetingProviderState::Ended;

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $this->assertSame(MeetingStatus::Active, $result->status);
    }

    public function test_enabled_without_a_cutoff_fails_closed(): void
    {
        config(['meetings.active_recovery.enabled' => true, 'meetings.active_recovery.cutoff' => null]);
        $meeting = $this->activeMeeting();
        $this->provider->inspectState = MeetingProviderState::Ended;

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $this->assertSame(MeetingStatus::Active, $result->status);
    }

    public function test_a_meeting_that_predates_the_cutoff_is_never_reinterpreted_as_stale(): void
    {
        $this->enableRecovery(cutoff: now()->subHour());
        $meeting = $this->activeMeeting(startedAt: now()->subDay());
        $this->provider->inspectState = MeetingProviderState::Ended;

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $this->assertSame(MeetingStatus::Active, $result->status);
        $this->assertSame(2, $result->lifecycle_version);
        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.end-recovered')->count());
    }

    public function test_an_active_meeting_with_no_start_time_is_never_recovered(): void
    {
        $this->enableRecovery();
        $meeting = Meeting::factory()->create([
            'school_class_id' => $this->activeClass()->id,
            'status' => MeetingStatus::Active,
            'actual_start_at' => null,
            'session_started_at' => null,
            'lifecycle_version' => 2,
        ]);
        $this->provider->inspectState = MeetingProviderState::Ended;

        $result = app(ReconcileMeetingLifecycle::class)->handle($meeting);

        $this->assertSame(MeetingStatus::Active, $result->status);
    }

    public function test_the_command_recovers_active_meetings_and_reports_each_state_separately(): void
    {
        $this->enableRecovery();
        $class = $this->activeClass();
        $host = $this->classTeacher($class);

        $active = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'lifecycle_version' => 2]);
        $ending = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Ending, 'lifecycle_version' => 3]);
        $untouched = Meeting::factory()->create(['school_class_id' => $class->id, 'host_user_id' => $host->id, 'status' => MeetingStatus::Cancelled]);
        $this->provider->inspectState = MeetingProviderState::Ended;

        $this->artisan('meetings:reconcile-lifecycle')
            ->expectsOutputToContain('active: inspected 1, recovered 1')
            ->expectsOutputToContain('ending: inspected 1, recovered 1')
            ->expectsOutputToContain('starting: inspected 0, recovered 0')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Ended, $active->fresh()->status);
        $this->assertSame(MeetingStatus::Ended, $ending->fresh()->status);
        $this->assertSame(MeetingStatus::Cancelled, $untouched->fresh()->status);
    }

    public function test_the_command_reports_active_meetings_it_is_not_allowed_to_touch(): void
    {
        config(['meetings.active_recovery.enabled' => false]);
        Meeting::factory()->active()->create([
            'school_class_id' => $this->activeClass()->id,
            'lifecycle_version' => 2,
        ]);
        $this->provider->inspectState = MeetingProviderState::Ended;

        $this->artisan('meetings:reconcile-lifecycle')
            ->expectsOutputToContain('skipped: 1 active meeting(s) outside the configured recovery window.')
            ->expectsOutputToContain('active: inspected 1, recovered 0')
            ->assertSuccessful();
    }

    public function test_the_command_dry_run_leaves_every_meeting_alone(): void
    {
        $this->enableRecovery();
        $meeting = Meeting::factory()->active()->create([
            'school_class_id' => $this->activeClass()->id,
            'lifecycle_version' => 2,
        ]);
        $this->provider->inspectState = MeetingProviderState::Ended;

        $this->artisan('meetings:reconcile-lifecycle', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, $meeting->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.end-recovered')->count());
    }

    public function test_the_lifecycle_reconciler_is_scheduled_and_cannot_overlap_itself(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'meetings:reconcile-lifecycle'));

        $this->assertCount(1, $events, 'The lifecycle reconciler must be scheduled exactly once.');
        $this->assertSame('* * * * *', $events->sole()->expression);
        $this->assertTrue($events->sole()->withoutOverlapping, 'The reconciler must hold the project-standard overlap lock.');
    }

    private function enableRecovery(?string $cutoff = null): void
    {
        config([
            'meetings.active_recovery.enabled' => true,
            'meetings.active_recovery.cutoff' => $cutoff ?? now()->subDay()->toIso8601String(),
        ]);
    }

    private function activeMeeting(?int $lifecycleVersion = null, ?\DateTimeInterface $startedAt = null): Meeting
    {
        $startedAt ??= now();

        return Meeting::factory()->active()->create([
            'school_class_id' => $this->activeClass()->id,
            'actual_start_at' => $startedAt,
            'session_started_at' => $startedAt,
            'lifecycle_version' => $lifecycleVersion ?? 2,
        ]);
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active]);
    }

    private function classTeacher(SchoolClass $class): User
    {
        $user = User::factory()->create();
        $user->assignRole('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id, 'current_slot' => 1]);

        return $user;
    }
}