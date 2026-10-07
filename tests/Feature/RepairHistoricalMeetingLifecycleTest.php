<?php

namespace Tests\Feature;

use App\Enums\MeetingProviderState;
use App\Enums\MeetingStatus;
use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\User;
use App\Services\LiveKit\LiveKitRoomManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The guarded one-time repair of historical lifecycle corruption.
 *
 * These run entirely against the test database. The real repair is only ever
 * reached through the same dry-run-by-default command an operator runs, so the
 * guarantee that matters most is that inspecting changes nothing and that every
 * refusal is a refusal rather than a guess.
 */
class RepairHistoricalMeetingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        // The repair never consults provider state for an audit-backed row, so no
        // fake is bound by default and an unexpected call fails loudly.
        $this->fakeRooms(MeetingProviderState::Ended, 0);
    }

    public function test_dry_run_reports_the_repair_without_writing_anything(): void
    {
        $meeting = $this->corruptMeeting(5, 4);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5]])
            ->expectsOutputToContain('audit_restoration')
            ->expectsOutputToContain('writes=0')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, $meeting->fresh()->status);
        $this->assertNull($meeting->fresh()->actual_end_at);
        $this->assertSame(4, $meeting->fresh()->lifecycle_version);
        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.lifecycle.repaired')->count());
    }

    public function test_audit_backed_repair_restores_the_recorded_end_exactly(): void
    {
        $endAt = '2026-09-19 09:04:46';
        $meeting = $this->corruptMeeting(5, 4, $endAt);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])->assertSuccessful();

        $fresh = $meeting->fresh();
        $this->assertSame(MeetingStatus::Ended, $fresh->status);
        $this->assertSame($endAt, $fresh->actual_end_at?->format('Y-m-d H:i:s'));
        // The version the real end already spent is preserved, not incremented.
        $this->assertSame(4, $fresh->lifecycle_version);
        $this->assertNull($fresh->last_provider_error);
    }

    public function test_repair_only_touches_lifecycle_fields_and_preserves_the_schedule(): void
    {
        // 7, 8 and 10 carry inverted schedules that are a separate, deliberately
        // unrepaired fault. Their schedule fields must survive this repair intact.
        $start = '2026-10-03 03:13:13';
        $end = '2026-09-20 10:14:03';
        $meeting = $this->corruptMeeting(7, 3, '2026-09-19 09:15:04', $start, $end);
        $actualStart = $meeting->actual_start_at;
        $updatedAt = $meeting->updated_at;

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [7], '--apply' => true])->assertSuccessful();

        $fresh = $meeting->fresh();
        $this->assertSame(MeetingStatus::Ended, $fresh->status);
        $this->assertSame($start, $fresh->scheduled_start_at?->format('Y-m-d H:i:s'));
        $this->assertSame($end, $fresh->scheduled_end_at?->format('Y-m-d H:i:s'));
        $this->assertTrue($actualStart->equalTo($fresh->actual_start_at));
        // Corrective restoration, not a new event: the row keeps its timestamp.
        $this->assertTrue($updatedAt->equalTo($fresh->updated_at));
    }

    /**
     * The meetings table declares scheduled_start_at ON UPDATE CURRENT_TIMESTAMP(),
     * so MySQL replaces it with NOW() on any update to the row. SQLite does not
     * implement that clause, so this test cannot prove the hazard is gone; it
     * pins the required behaviour by using a sentinel the repair must hand back
     * untouched, and the real guard is naming the column on the write plus the
     * pending migration that drops the clause.
     */
    public function test_repair_hands_back_the_schedule_it_found_untouched(): void
    {
        $meeting = $this->corruptMeeting(5, 4, '2026-09-19 09:04:46', '2011-02-03 04:05:06', '2031-12-31 23:59:59');

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])->assertSuccessful();

        $fresh = $meeting->fresh();
        $this->assertSame('2011-02-03 04:05:06', $fresh->scheduled_start_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2031-12-31 23:59:59', $fresh->scheduled_end_at?->format('Y-m-d H:i:s'));
        $this->assertSame(MeetingStatus::Ended, $fresh->status);
    }

    public function test_repair_creates_an_additive_audit_record(): void
    {
        $meeting = $this->corruptMeeting(5, 4);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])->assertSuccessful();

        $repaired = AuditLog::query()->where('action', 'meeting.lifecycle.repaired')->where('target_id', 5)->sole();
        $after = $repaired->after;
        $this->assertSame('historical_lifecycle_corruption', $after['reason']);
        $this->assertSame('audit_restoration', $after['repair_type']);
        $this->assertSame($this->endAuditId(5), $after['source_audit_id']);
        $this->assertSame(MeetingStatus::Ended->value, $after['status']);
        $this->assertSame('active', $repaired->before['status']);
        // The original end record is never rewritten or impersonated.
        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.end-succeeded')->where('target_id', 5)->where('id', $this->endAuditId(5))->whereNull('before')->count());
    }

    public function test_a_second_run_is_idempotent(): void
    {
        $meeting = $this->corruptMeeting(5, 4);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])->assertSuccessful();
        $after = $meeting->fresh();

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])
            ->expectsOutputToContain('ALREADY_ENDED')
            ->assertSuccessful();

        $this->assertTrue($after->updated_at->equalTo($meeting->fresh()->updated_at));
        $this->assertSame(1, AuditLog::query()->where('action', 'meeting.lifecycle.repaired')->where('target_id', 5)->count());
    }

    public function test_a_meeting_that_is_no_longer_active_is_left_alone(): void
    {
        $this->corruptMeeting(5, 4);
        Meeting::query()->where('id', 5)->update(['status' => MeetingStatus::Cancelled->value]);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])
            ->expectsOutputToContain('NOT_ACTIVE_cancelled')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Cancelled, Meeting::query()->find(5)->status);
    }

    public function test_a_row_whose_version_moved_on_is_refused(): void
    {
        $this->corruptMeeting(5, 4);
        // A legitimate transition spent a version the end evidence knows nothing
        // about, so the evidence no longer describes this row.
        Meeting::query()->where('id', 5)->update(['lifecycle_version' => 9]);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])
            ->expectsOutputToContain('FINGERPRINT_VERSION_MISMATCH')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, Meeting::query()->find(5)->status);
    }

    public function test_a_row_with_no_end_audit_is_refused(): void
    {
        $this->corruptMeeting(5, 4, endAudit: false);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])
            ->expectsOutputToContain('MISSING_END_AUDIT')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, Meeting::query()->find(5)->status);
    }

    public function test_a_row_with_two_end_audits_is_refused_as_ambiguous(): void
    {
        $this->corruptMeeting(5, 4);
        $this->endAudit(5, 'ended', 4, '2026-09-19 10:00:00');

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])
            ->expectsOutputToContain('AMBIGUOUS_END_AUDIT')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, Meeting::query()->find(5)->status);
    }

    public function test_a_row_superseded_by_a_newer_lifecycle_event_is_refused(): void
    {
        $this->corruptMeeting(5, 4);
        $this->lifecycleAudit(5, 'meeting.end-recovered', ['status' => 'ended', 'lifecycle_version' => 4]);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])
            ->expectsOutputToContain('SUPERSEDED_BY_NEWER_LIFECYCLE')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, Meeting::query()->find(5)->status);
    }

    public function test_an_end_audit_without_a_timestamp_is_refused(): void
    {
        $this->corruptMeeting(5, 4, endAudit: false);
        $this->endAudit(5, 'ended', 4, null);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])
            ->expectsOutputToContain('AUDIT_MISSING_END_TIMESTAMP')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, Meeting::query()->find(5)->status);
    }

    public function test_an_id_outside_the_allowlist_is_never_repaired(): void
    {
        // A meeting the operator did not approve, left Active on purpose.
        $stranger = $this->corruptMeeting(9999, 2);
        $this->endAudit(9999, 'ended', 2, '2026-10-01 10:00:00');

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [9999], '--apply' => true])
            ->expectsOutputToContain('NOT_APPROVED')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, $stranger->fresh()->status);
        $this->assertNull($stranger->fresh()->actual_end_at);
    }

    public function test_provider_recovery_ends_the_meeting_and_leaves_the_end_time_null(): void
    {
        $this->fakeRooms(MeetingProviderState::Ended, 2);
        $meeting = $this->activeMeeting(37, 2);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [37], '--apply' => true])->assertSuccessful();

        $fresh = $meeting->fresh();
        $this->assertSame(MeetingStatus::Ended, $fresh->status);
        $this->assertSame(3, $fresh->lifecycle_version);
        // No witness ever saw this room end, so no time is invented.
        $this->assertNull($fresh->actual_end_at);

        $recovered = AuditLog::query()->where('action', 'meeting.end-recovered')->where('target_id', 37)->sole();
        $this->assertSame('historical_provider_reconciliation', $recovered->after['source']);
        $this->assertSame('provider_reconciliation', $recovered->after['repair_type']);
        $this->assertSame(MeetingProviderState::Ended->value, $recovered->after['provider_state']);
        $this->assertFalse($recovered->after['exact_end_timestamp_available']);
        $this->assertNull($recovered->after['actual_end_at']);
        $this->assertArrayHasKey('observed_at', $recovered->after);
    }

    public function test_provider_recovery_is_skipped_while_the_room_still_exists(): void
    {
        $this->fakeRooms(MeetingProviderState::Active, 1);
        $this->activeMeeting(37, 2);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [37], '--apply' => true])
            ->expectsOutputToContain('PROVIDER_ACTIVE')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, Meeting::query()->find(37)->status);
    }

    public function test_provider_recovery_is_skipped_when_the_provider_cannot_be_reached(): void
    {
        $this->fakeRooms(MeetingProviderState::Unknown, 1);
        $this->activeMeeting(37, 2);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [37], '--apply' => true])
            ->expectsOutputToContain('PROVIDER_UNKNOWN')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, Meeting::query()->find(37)->status);
    }

    public function test_provider_recovery_defers_to_an_end_someone_already_recorded(): void
    {
        $this->fakeRooms(MeetingProviderState::Ended, 0);
        $this->activeMeeting(37, 2);
        $this->lifecycleAudit(37, 'meeting.end-requested', ['status' => 'ending', 'lifecycle_version' => 3]);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [37], '--apply' => true])
            ->expectsOutputToContain('HUMAN_END_ALREADY_RECORDED')
            ->assertSuccessful();

        $this->assertSame(MeetingStatus::Active, Meeting::query()->find(37)->status);
    }

    public function test_provider_recovery_is_idempotent(): void
    {
        $this->fakeRooms(MeetingProviderState::Ended, 2);
        $meeting = $this->activeMeeting(37, 2);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [37], '--apply' => true])->assertSuccessful();
        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [37], '--apply' => true])
            ->expectsOutputToContain('ALREADY_ENDED')
            ->assertSuccessful();

        $this->assertSame(3, $meeting->fresh()->lifecycle_version);
        $this->assertSame(1, AuditLog::query()->where('action', 'meeting.end-recovered')->where('target_id', 37)->count());
    }

    public function test_the_two_paths_report_separately_and_never_share_logic(): void
    {
        $this->fakeRooms(MeetingProviderState::Ended, 1);
        $this->corruptMeeting(5, 4);
        $this->activeMeeting(37, 2);

        // One row per allowlist entry that does not exist is reported as skipped,
        // so the two real rows are counted apart from the 16 absent ones.
        $this->artisan('meetings:repair-historical-lifecycle')
            ->expectsOutputToContain('type=audit_restoration | result=ELIGIBLE')
            ->expectsOutputToContain('type=provider_reconciliation | result=ELIGIBLE')
            ->expectsOutputToContain('Summary: audit-restorable=1, provider-recovery=1, skipped=16, writes=0.')
            ->assertSuccessful();
    }

    public function test_the_repair_does_not_depend_on_the_active_recovery_backstop(): void
    {
        config(['meetings.active_recovery.enabled' => false, 'meetings.active_recovery.cutoff' => null]);
        $this->corruptMeeting(5, 4);

        $this->artisan('meetings:repair-historical-lifecycle', ['--meeting' => [5], '--apply' => true])->assertSuccessful();

        $this->assertSame(MeetingStatus::Ended, Meeting::query()->find(5)->status);
        // The standing backstop stays off: this one-off tool never turns it on.
        $this->assertFalse((bool) config('meetings.active_recovery.enabled'));
        $this->assertNull(config('meetings.active_recovery.cutoff'));
    }

    private function fakeRooms(MeetingProviderState $state, int $expectedCalls): void
    {
        $rooms = Mockery::mock(LiveKitRoomManager::class);
        $rooms->shouldReceive('inspect')->times($expectedCalls)->andReturn($state);
        $this->app->instance(LiveKitRoomManager::class, $rooms);
    }

    private function corruptMeeting(
        int $id,
        int $lifecycleVersion,
        ?string $endAt = '2026-09-19 09:04:46',
        ?string $scheduledStart = null,
        ?string $scheduledEnd = null,
        bool $endAudit = true,
    ): Meeting {
        $meeting = Meeting::factory()->create([
            'id' => $id,
            'status' => MeetingStatus::Active,
            'lifecycle_version' => $lifecycleVersion,
            'actual_start_at' => '2026-09-19 09:00:00',
            'actual_end_at' => null,
            'scheduled_start_at' => $scheduledStart ?? '2026-09-19 10:00:00',
            'scheduled_end_at' => $scheduledEnd ?? '2026-09-19 11:00:00',
            'last_provider_error' => 'Meeting provider start failed.',
        ]);

        if ($endAudit) {
            $this->endAudit($id, 'ended', $lifecycleVersion, $endAt);
        }

        return $meeting;
    }

    private function activeMeeting(int $id, int $lifecycleVersion): Meeting
    {
        return Meeting::factory()->create([
            'id' => $id,
            'status' => MeetingStatus::Active,
            'lifecycle_version' => $lifecycleVersion,
            'actual_start_at' => '2026-10-03 11:05:52',
            'session_started_at' => '2026-10-03 11:05:52',
            'actual_end_at' => null,
        ]);
    }

    private function endAudit(int $meetingId, string $status, int $lifecycleVersion, ?string $endAt): AuditLog
    {
        return $this->lifecycleAudit(
            $meetingId,
            'meeting.end-succeeded',
            array_filter([
                'status' => $status,
                'lifecycle_version' => $lifecycleVersion,
                'actual_end_at' => $endAt ? now()->parse($endAt)->toIso8601String() : null,
            ], fn ($value) => $value !== null),
            before: ['status' => 'ending', 'lifecycle_version' => $lifecycleVersion - 1],
        );
    }

    /** @param array<string, mixed> $after */
    private function lifecycleAudit(int $meetingId, string $action, array $after = [], ?array $before = null): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $this->actor->id,
            'action' => $action,
            'target_type' => (new Meeting)->getMorphClass(),
            'target_id' => $meetingId,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function endAuditId(int $meetingId): int
    {
        return AuditLog::query()
            ->where('target_type', (new Meeting)->getMorphClass())
            ->where('target_id', $meetingId)
            ->where('action', 'meeting.end-succeeded')
            ->value('id');
    }
}