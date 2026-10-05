<?php

namespace App\Console\Commands;

use App\Enums\MeetingProviderState;
use App\Enums\MeetingStatus;
use App\Models\AuditLog;
use App\Models\Meeting;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRoomManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-time, guarded repair of historical meeting lifecycle corruption.
 *
 * Two different faults are repaired here and they are deliberately not sharing
 * logic, because what is knowable about each end is different.
 *
 * The audit-backed rows were ended by a person, through End for everyone, and
 * the application's own audit record of that end is still intact. Nothing is
 * being inferred for them: the record already carries the status, the
 * lifecycle version and the exact end timestamp the transition produced. The
 * repair puts the row back to that recorded state and nothing else. Their
 * lifecycle version is deliberately NOT incremented, because the version was
 * already spent by the end that really happened; bumping it would invent a
 * nineteenth transition and break the correspondence between a row and the
 * audit that describes it.
 *
 * The provider-reconciled row is the opposite case. It genuinely never ended:
 * there is no end event in its history at all, and only the provider can say
 * the room is gone. So it does get one new transition, one version, and its end
 * time stays null because no witness ever saw the room end. That is the same
 * shape the standing backstop uses for a meeting it recovers, kept honest by
 * the same rule: never invent a time nobody observed.
 *
 * Both paths are dry-run by default and derive every value from evidence. An
 * operator never types a recovery timestamp.
 */
class RepairHistoricalMeetingLifecycle extends Command
{
    /**
     * Rows ended by a person whose end-succeeded audit survives intact.
     *
     * This is an explicit allowlist, never a query. A row that is not named here
     * is never touched, so no Active meeting anywhere can be swept up by a broad
     * update, and a meeting that becomes Active after this was written is
     * invisible to it.
     */
    private const AUDIT_BACKED_IDS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 15, 16, 17, 18];

    /** Rows with no end event at all, repaired from provider state alone. */
    private const PROVIDER_RECONCILED_IDS = [37];

    /** Anything that would mean this row's lifecycle moved on since the evidence was taken. */
    private const LIFECYCLE_ACTIONS = [
        'meeting.start-requested', 'meeting.start-succeeded', 'meeting.start-failed', 'meeting.start-recovered',
        'meeting.end-requested', 'meeting.end-succeeded', 'meeting.end-recovered', 'meeting.end-failed',
        'meeting.provider-finished', 'meeting.cancelled', 'meeting.lifecycle.repaired',
    ];

    /** An end already on record means the provider path must keep its hands off. */
    private const END_ACTIONS = [
        'meeting.end-requested', 'meeting.end-succeeded', 'meeting.end-recovered',
        'meeting.end-failed', 'meeting.provider-finished', 'meeting.cancelled',
    ];

    protected $signature = 'meetings:repair-historical-lifecycle
        {--apply : Apply only fully eligible, evidence-backed repairs}
        {--meeting=* : Restrict the inspection to one or more meeting IDs}';

    protected $description = 'Dry-run guarded repair of historical meeting lifecycle corruption; use --apply only after reviewing the proposals';

    public function handle(AuditLogger $audit, LiveKitRoomManager $rooms): int
    {
        $plans = $this->plans($rooms);
        $this->renderPlans($plans);

        if (! $this->option('apply')) {
            $this->summary($plans, 0);

            return self::SUCCESS;
        }

        $writes = 0;

        foreach ($plans->where('result', 'ELIGIBLE') as $plan) {
            // The provider is never consulted while a row lock is held. Its
            // answer is taken in the planning pass above; what is re-checked
            // under the lock is the local row, which is what a concurrent teacher
            // would change. A human end moves status and lifecycle version, so
            // the re-plan below is enough to lose the race safely.
            $applied = DB::transaction(function () use ($plan, $audit, $rooms, &$writes) {
                $locked = Meeting::query()->lockForUpdate()->find($plan['meeting_id']);
                if (! $locked) {
                    $this->warn("Meeting {$plan['meeting_id']}: vanished before it could be repaired.");

                    return false;
                }

                $verified = $this->plan($locked, $rooms);
                if ($verified['result'] !== 'ELIGIBLE') {
                    $this->warn("Meeting {$plan['meeting_id']}: skipped during locked re-check ({$verified['reason']}).");

                    return false;
                }

                $this->apply($locked, $verified, $audit);
                $writes++;

                return true;
            });
        }

        $this->summary($plans, $writes);

        return self::SUCCESS;
    }

    private function apply(Meeting $locked, array $plan, AuditLogger $audit): void
    {
        $before = $locked->only('status', 'lifecycle_version', 'actual_end_at');

        // This is a corrective restoration of a past event, not a new one, so the
        // row keeps the timestamp it already carried. The repair is made
        // traceable in its own audit record instead, exactly as the audited
        // schedule repair does.
        $locked->timestamps = false;
        $locked->forceFill([
            'status' => $plan['proposed_status'],
            'lifecycle_version' => $plan['proposed_lifecycle_version'],
            'actual_end_at' => $plan['proposed_end'],
            'last_provider_error' => null,
            // Written back unchanged on purpose. This column is declared
            // ON UPDATE CURRENT_TIMESTAMP(), so MySQL replaces it with NOW() on
            // *any* update to the row, even one that never names it. Naming it
            // explicitly is the only way to stop that clause firing, and losing
            // the original schedule here would be worse than the corruption
            // being repaired. Removing the clause itself is a schema migration
            // and belongs to its own change.
            'scheduled_start_at' => $locked->scheduled_start_at,
        ])->save();

        if ($plan['repair_type'] === 'audit_restoration') {
            $audit->log('meeting.lifecycle.repaired', $locked, $before, [
                'reason' => 'historical_lifecycle_corruption',
                'repair_type' => 'audit_restoration',
                'source_audit_id' => $plan['audit_id'],
                'status' => $plan['proposed_status'],
                'lifecycle_version' => $plan['proposed_lifecycle_version'],
                'actual_end_at' => $plan['proposed_end']?->toIso8601String(),
                'restored_from_audit_created_at' => $plan['audit_created_at']?->toIso8601String(),
            ]);

            return;
        }

        $audit->log('meeting.end-recovered', $locked, $before, [
            'source' => 'historical_provider_reconciliation',
            'repair_type' => 'provider_reconciliation',
            'previous_status' => $before['status'],
            'provider_state' => MeetingProviderState::Ended->value,
            'exact_end_timestamp_available' => false,
            'status' => $plan['proposed_status'],
            'lifecycle_version' => $plan['proposed_lifecycle_version'],
            'actual_end_at' => null,
            'observed_at' => now()->toIso8601String(),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function plans(LiveKitRoomManager $rooms): Collection
    {
        return collect($this->requestedIds())
            ->map(fn (int $id) => $this->plan(Meeting::query()->find($id), $rooms));
    }

    /** @return list<int> */
    private function requestedIds(): array
    {
        $requested = collect($this->option('meeting'))
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false)
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $requested === []
            ? [...self::AUDIT_BACKED_IDS, ...self::PROVIDER_RECONCILED_IDS]
            : $requested;
    }

    /** @return array<string, mixed> */
    private function plan(?Meeting $meeting, LiveKitRoomManager $rooms): array
    {
        if (! $meeting) {
            return $this->skipped(null, 'MISSING_MEETING');
        }

        if (in_array($meeting->id, self::AUDIT_BACKED_IDS, true)) {
            return $this->auditBackedPlan($meeting);
        }

        if (in_array($meeting->id, self::PROVIDER_RECONCILED_IDS, true)) {
            return $this->providerPlan($meeting, $rooms);
        }

        return $this->skipped($meeting->id, 'NOT_APPROVED', $meeting);
    }

    /**
     * Restore a row to the exact state its own end-succeeded audit recorded.
     *
     * Every guard here exists to answer one question: is the evidence still
     * about this row as it stands now? The version equality is the sharpest of
     * them. The corruption overwrote status and the end timestamp but left the
     * lifecycle version at the value the real end had already spent, so the
     * version recorded in the audit matching the version on the row is what
     * proves no legitimate transition has happened since.
     *
     * @return array<string, mixed>
     */
    private function auditBackedPlan(Meeting $meeting): array
    {
        if ($meeting->status === MeetingStatus::Ended) {
            return $this->skipped($meeting->id, 'ALREADY_ENDED', $meeting);
        }

        if ($meeting->status !== MeetingStatus::Active) {
            return $this->skipped($meeting->id, 'NOT_ACTIVE_'.$meeting->status->value, $meeting);
        }

        if ($meeting->actual_end_at !== null) {
            return $this->skipped($meeting->id, 'ALREADY_REPAIRED', $meeting);
        }

        $audits = $this->lifecycleAudits($meeting->id);
        $successes = $audits->where('action', 'meeting.end-succeeded');

        if ($successes->isEmpty()) {
            return $this->skipped($meeting->id, 'MISSING_END_AUDIT', $meeting);
        }

        if ($successes->count() > 1) {
            return $this->skipped($meeting->id, 'AMBIGUOUS_END_AUDIT', $meeting);
        }

        $source = $successes->sole();
        $before = $this->auditPayload($source->before);
        $after = $this->auditPayload($source->after);

        if (($after['status'] ?? null) !== MeetingStatus::Ended->value) {
            return $this->skipped($meeting->id, 'AUDIT_NOT_AN_END', $meeting);
        }

        $endAt = $this->auditTimestamp($after['actual_end_at'] ?? null);
        if ($endAt === null) {
            return $this->skipped($meeting->id, 'AUDIT_MISSING_END_TIMESTAMP', $meeting);
        }

        $from = $before['lifecycle_version'] ?? null;
        $to = $after['lifecycle_version'] ?? null;
        if (! is_int($from) || ! is_int($to) || $from + 1 !== $to) {
            return $this->skipped($meeting->id, 'AUDIT_VERSION_INCONSISTENT', $meeting);
        }

        if ($to !== $meeting->lifecycle_version) {
            return $this->skipped($meeting->id, 'FINGERPRINT_VERSION_MISMATCH', $meeting);
        }

        $newer = $audits->where('id', '>', $source->id);
        if ($newer->isNotEmpty()) {
            return $this->skipped($meeting->id, 'SUPERSEDED_BY_NEWER_LIFECYCLE', $meeting);
        }

        return [
            ...$this->base($meeting),
            'result' => 'ELIGIBLE',
            'reason' => 'AUDIT_BACKED',
            'repair_type' => 'audit_restoration',
            'audit_id' => $source->id,
            'audit_created_at' => CarbonImmutable::parse((string) $source->created_at)->utc(),
            'proposed_status' => MeetingStatus::Ended,
            // Unchanged on purpose: this end already spent this version.
            'proposed_lifecycle_version' => $meeting->lifecycle_version,
            'proposed_end' => $endAt,
        ];
    }

    /**
     * End a row the provider has already ended, with no end event of its own.
     *
     * This path deliberately does not consult the audit chain for a timestamp,
     * because there is none to consult. It refuses outright if any end event
     * exists, since that would mean a person had already dealt with the meeting
     * and the provider's silence says nothing new.
     *
     * @return array<string, mixed>
     */
    private function providerPlan(Meeting $meeting, LiveKitRoomManager $rooms): array
    {
        if ($meeting->status === MeetingStatus::Ended) {
            return $this->skipped($meeting->id, 'ALREADY_ENDED', $meeting);
        }

        if ($meeting->status !== MeetingStatus::Active) {
            return $this->skipped($meeting->id, 'NOT_ACTIVE_'.$meeting->status->value, $meeting);
        }

        if ($meeting->actual_end_at !== null) {
            return $this->skipped($meeting->id, 'ALREADY_REPAIRED', $meeting);
        }

        $ended = $this->lifecycleAudits($meeting->id)->whereIn('action', self::END_ACTIONS);
        if ($ended->isNotEmpty()) {
            return $this->skipped($meeting->id, 'HUMAN_END_ALREADY_RECORDED', $meeting);
        }

        $state = $rooms->inspect($meeting->livekit_room_name);
        if ($state !== MeetingProviderState::Ended) {
            return $this->skipped($meeting->id, 'PROVIDER_'.strtoupper($state->value), $meeting);
        }

        return [
            ...$this->base($meeting),
            'result' => 'ELIGIBLE',
            'reason' => 'PROVIDER_CONFIRMED_ENDED',
            'repair_type' => 'provider_reconciliation',
            'audit_id' => null,
            'audit_created_at' => null,
            'proposed_status' => MeetingStatus::Ended,
            // A real transition this time: nothing has spent this version yet.
            'proposed_lifecycle_version' => $meeting->lifecycle_version + 1,
            'proposed_end' => null,
        ];
    }

    /** @return Collection<int, AuditLog> */
    private function lifecycleAudits(int $meetingId): Collection
    {
        return AuditLog::query()
            ->where('target_type', (new Meeting)->getMorphClass())
            ->where('target_id', $meetingId)
            ->whereIn('action', self::LIFECYCLE_ACTIONS)
            ->orderBy('id')
            ->get();
    }

    /**
     * The audit payload, whether the model handed back a decoded array or raw
     * JSON. An empty payload is an empty payload either way, never an error.
     *
     * @return array<string, mixed>
     */
    private function auditPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function auditTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function base(Meeting $meeting): array
    {
        return [
            'meeting_id' => $meeting->id,
            'current_status' => $meeting->status,
            'current_lifecycle_version' => $meeting->lifecycle_version,
            'current_end' => $meeting->actual_end_at,
        ];
    }

    /** @return array<string, mixed> */
    private function skipped(?int $meetingId, string $reason, ?Meeting $meeting = null): array
    {
        return [
            'meeting_id' => $meetingId,
            'current_status' => $meeting?->status,
            'current_lifecycle_version' => $meeting?->lifecycle_version,
            'current_end' => $meeting?->actual_end_at,
            'result' => 'SKIPPED',
            'reason' => $reason,
            'repair_type' => null,
            'audit_id' => null,
            'audit_created_at' => null,
            'proposed_status' => null,
            'proposed_lifecycle_version' => null,
            'proposed_end' => null,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $plans */
    private function renderPlans(Collection $plans): void
    {
        foreach ($plans as $plan) {
            $this->line(implode(' | ', [
                'meeting='.$plan['meeting_id'],
                'current='.$this->label($plan['current_status']).' lv='.$plan['current_lifecycle_version']
                    .' end='.$this->timestamp($plan['current_end']),
                'proposed='.$this->label($plan['proposed_status']).' lv='.$plan['proposed_lifecycle_version']
                    .' end='.$this->timestamp($plan['proposed_end']),
                'source='.($plan['audit_id'] !== null ? 'audit#'.$plan['audit_id'] : ($plan['repair_type'] === 'provider_reconciliation' ? 'provider' : '-')),
                'type='.($plan['repair_type'] ?? '-'),
                'result='.$plan['result'],
                'reason='.$plan['reason'],
            ]));
        }
    }

    /** @param Collection<int, array<string, mixed>> $plans */
    private function summary(Collection $plans, int $writes): void
    {
        $eligible = $plans->where('result', 'ELIGIBLE');
        $this->newLine();
        $this->info(sprintf(
            'Summary: audit-restorable=%d, provider-recovery=%d, skipped=%d, writes=%d.',
            $eligible->where('repair_type', 'audit_restoration')->count(),
            $eligible->where('repair_type', 'provider_reconciliation')->count(),
            $plans->where('result', 'SKIPPED')->count(),
            $writes,
        ));
    }

    private function timestamp(?\DateTimeInterface $value): string
    {
        return $value?->toIso8601String() ?? '-';
    }

    private function label(?MeetingStatus $status): string
    {
        return $status?->value ?? '-';
    }
}