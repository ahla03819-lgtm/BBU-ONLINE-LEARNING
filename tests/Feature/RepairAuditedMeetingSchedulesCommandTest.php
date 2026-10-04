<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Meeting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RepairAuditedMeetingSchedulesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_proposes_an_audit_backed_repair_without_writing(): void
    {
        [$meeting] = $this->recoverableMeeting(1);
        $before = $meeting->only('scheduled_start_at', 'scheduled_end_at', 'updated_at');

        $this->assertSame(0, Artisan::call('meetings:repair-audited-schedules', ['--meeting' => [$meeting->id]]));
        $output = Artisan::output();
        $this->assertStringContainsString("meeting={$meeting->id}", $output);
        $this->assertStringContainsString('proposed=2026-08-26T21:49:00+00:00 to 2026-08-26T22:49:00+00:00', $output);
        $this->assertStringContainsString('result=ELIGIBLE', $output);
        $this->assertStringContainsString('writes=0.', $output);

        $meeting->refresh();
        $this->assertSame($before['scheduled_start_at']->toIso8601String(), $meeting->scheduled_start_at->toIso8601String());
        $this->assertSame($before['scheduled_end_at']->toIso8601String(), $meeting->scheduled_end_at->toIso8601String());
        $this->assertSame($before['updated_at']->toIso8601String(), $meeting->updated_at->toIso8601String());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'meeting.schedule.repaired', 'target_id' => $meeting->id]);
    }

    public function test_apply_restores_only_schedule_fields_preserves_updated_at_and_writes_a_repair_audit(): void
    {
        [$meeting, $source] = $this->recoverableMeeting(2);
        $before = $meeting->only('status', 'actual_start_at', 'actual_end_at', 'session_started_at', 'lifecycle_version', 'meeting_series_id', 'updated_at');

        $this->artisan('meetings:repair-audited-schedules', ['--meeting' => [$meeting->id], '--apply' => true])
            ->expectsOutputToContain('writes=1.')
            ->assertSuccessful();

        $meeting->refresh();
        $this->assertSame('2026-08-26T21:49:00+00:00', $meeting->scheduled_start_at->toIso8601String());
        $this->assertSame('2026-08-26T22:49:00+00:00', $meeting->scheduled_end_at->toIso8601String());
        foreach ($before as $field => $value) {
            $this->assertSame($value instanceof \DateTimeInterface ? $value->toIso8601String() : $value, $meeting->{$field} instanceof \DateTimeInterface ? $meeting->{$field}->toIso8601String() : $meeting->{$field}, $field);
        }
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'meeting.schedule.repaired',
            'target_type' => Meeting::class,
            'target_id' => $meeting->id,
        ]);
        $repair = AuditLog::query()->where('action', 'meeting.schedule.repaired')->sole();
        $this->assertSame('historical_schedule_recovery', $repair->after['reason']);
        $this->assertSame($source->id, $repair->after['source_audit_id']);
    }

    public function test_valid_interval_is_already_correct_and_a_second_apply_is_idempotent(): void
    {
        [$meeting] = $this->recoverableMeeting(3);

        $this->artisan('meetings:repair-audited-schedules', ['--meeting' => [$meeting->id], '--apply' => true])->assertSuccessful();
        $this->artisan('meetings:repair-audited-schedules', ['--meeting' => [$meeting->id], '--apply' => true])
            ->expectsOutputToContain('result=ALREADY_CORRECT')
            ->expectsOutputToContain('writes=0.')
            ->assertSuccessful();

        $this->assertSame(1, AuditLog::query()->where('action', 'meeting.schedule.repaired')->count());
    }

    public function test_missing_or_ambiguous_audit_metadata_is_skipped_without_a_repair_audit(): void
    {
        $missing = $this->corruptedMeeting(4);
        $ambiguous = $this->corruptedMeeting(5);
        $this->creationAudit($ambiguous, '2026-08-26T21:49:00Z', '2026-08-26T22:49:00Z');
        $this->creationAudit($ambiguous, '2026-08-26T21:50:00Z', '2026-08-26T22:50:00Z');
        $this->creationAudit($missing, null, '2026-08-26T22:49:00Z');

        $this->artisan('meetings:repair-audited-schedules', ['--meeting' => [$missing->id, $ambiguous->id], '--apply' => true])
            ->expectsOutputToContain('MISSING_AUDIT_SCHEDULE_METADATA')
            ->expectsOutputToContain('AMBIGUOUS_AUDIT_SOURCE')
            ->expectsOutputToContain('writes=0.')
            ->assertSuccessful();

        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.schedule.repaired')->count());
    }

    public function test_audit_target_mismatch_and_unapproved_lifecycle_only_id_are_refused(): void
    {
        $mismatch = $this->corruptedMeeting(6);
        $other = $this->corruptedMeeting(99);
        $this->creationAudit($other, '2026-08-26T21:49:00Z', '2026-08-26T22:49:00Z');
        $lifecycleOnly = $this->corruptedMeeting(7);

        $this->artisan('meetings:repair-audited-schedules', ['--meeting' => [$mismatch->id, $lifecycleOnly->id], '--apply' => true])
            ->expectsOutputToContain('NO_EXACT_AUDIT_SOURCE')
            ->expectsOutputToContain('NOT_APPROVED')
            ->expectsOutputToContain('writes=0.')
            ->assertSuccessful();

        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.schedule.repaired')->count());
    }

    public function test_current_corruption_fingerprint_must_match_before_a_repair_is_proposed(): void
    {
        $meeting = $this->corruptedMeeting(9, '2026-10-03 03:13:13', '2026-10-02 20:13:12');
        $this->creationAudit($meeting, '2026-08-26T21:49:00Z', '2026-08-26T22:49:00Z');

        $this->artisan('meetings:repair-audited-schedules', ['--meeting' => [$meeting->id], '--apply' => true])
            ->expectsOutputToContain('CURRENT_FINGERPRINT_MISMATCH')
            ->expectsOutputToContain('writes=0.')
            ->assertSuccessful();

        $this->assertSame(0, AuditLog::query()->where('action', 'meeting.schedule.repaired')->count());
    }

    /** @return array{Meeting, AuditLog} */
    private function recoverableMeeting(int $id): array
    {
        $meeting = $this->corruptedMeeting($id);

        return [$meeting, $this->creationAudit($meeting, '2026-08-26T21:49:00Z', '2026-08-26T22:49:00Z')];
    }

    private function corruptedMeeting(int $id, string $start = '2026-10-03 03:13:13', string $updatedAt = '2026-10-02 20:13:13'): Meeting
    {
        return Meeting::factory()->create([
            'id' => $id,
            'scheduled_start_at' => $start,
            'scheduled_end_at' => '2026-08-26 22:49:00',
            'actual_start_at' => '2026-08-26 18:24:01',
            'actual_end_at' => '2026-08-26 18:50:57',
            'session_started_at' => '2026-08-26 18:24:01',
            'lifecycle_version' => 9,
            'updated_at' => CarbonImmutable::parse($updatedAt),
        ]);
    }

    private function creationAudit(Meeting $meeting, ?string $start, ?string $end): AuditLog
    {
        return AuditLog::query()->create([
            'action' => 'meeting.created',
            'target_type' => Meeting::class,
            'target_id' => $meeting->id,
            'before' => [],
            'after' => array_filter([
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
            ], fn ($value) => $value !== null),
        ]);
    }
}
