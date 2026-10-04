<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Meeting;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class RepairAuditedMeetingSchedules extends Command
{
    private const RECOVERABLE_IDS = [1, 2, 3, 4, 5, 6, 9, 11, 12, 13, 14, 15, 16, 17, 18, 19, 22, 23, 24];

    private const UNRECOVERABLE_IDS = [7, 8, 10];

    protected $signature = 'meetings:repair-audited-schedules
        {--apply : Apply only fully eligible audit-backed repairs}
        {--meeting=* : Restrict the inspection to one or more meeting IDs}';

    protected $description = 'Dry-run audited historical meeting schedule repairs; use --apply only after reviewing the proposals';

    public function handle(AuditLogger $audit): int
    {
        $plans = $this->plans();
        $this->renderPlans($plans);

        if (! $this->option('apply')) {
            $this->summary($plans, 0);

            return self::SUCCESS;
        }

        $writes = 0;

        DB::transaction(function () use ($plans, $audit, &$writes): void {
            foreach ($plans->where('result', 'ELIGIBLE') as $plan) {
                $locked = Meeting::query()->lockForUpdate()->find($plan['meeting_id']);
                $verified = $locked ? $this->plan($locked) : $this->skipped($plan['meeting_id'], 'MISSING_MEETING');

                if ($verified['result'] !== 'ELIGIBLE') {
                    $this->warn("Meeting {$plan['meeting_id']}: skipped during locked re-check ({$verified['reason']}).");

                    continue;
                }

                $before = [
                    'scheduled_start_at' => $locked->scheduled_start_at?->toIso8601String(),
                    'scheduled_end_at' => $locked->scheduled_end_at?->toIso8601String(),
                ];

                // This is a historical correction, not a new scheduling event.
                // Keep the original row timestamp and make the repair traceable in
                // its own audit record below.
                $locked->timestamps = false;
                $locked->forceFill([
                    'scheduled_start_at' => $verified['proposed_start'],
                    'scheduled_end_at' => $verified['proposed_end'],
                ])->save();

                $audit->log('meeting.schedule.repaired', $locked, $before, [
                    'reason' => 'historical_schedule_recovery',
                    'source_audit_id' => $verified['audit_id'],
                    'scheduled_start_at' => $locked->scheduled_start_at?->toIso8601String(),
                    'scheduled_end_at' => $locked->scheduled_end_at?->toIso8601String(),
                ]);
                $writes++;
            }
        });

        $this->summary($plans, $writes);

        return self::SUCCESS;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function plans(): Collection
    {
        return collect($this->requestedIds())
            ->map(fn (int $id) => $this->plan(Meeting::query()->find($id)));
    }

    /** @return list<int> */
    private function requestedIds(): array
    {
        $requested = collect($this->option('meeting'))->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $requested === [] ? [...self::RECOVERABLE_IDS, ...self::UNRECOVERABLE_IDS] : $requested;
    }

    /** @return array<string, mixed> */
    private function plan(?Meeting $meeting): array
    {
        if (! $meeting) {
            return $this->skipped(null, 'MISSING_MEETING');
        }

        if (! in_array($meeting->id, self::RECOVERABLE_IDS, true)) {
            return $this->skipped($meeting->id, 'NOT_APPROVED', $meeting);
        }

        if (! $meeting->scheduled_start_at || ! $meeting->scheduled_end_at) {
            return $this->skipped($meeting->id, 'MISSING_CURRENT_INTERVAL');
        }

        if ($meeting->scheduled_end_at->gt($meeting->scheduled_start_at)) {
            return [
                ...$this->base($meeting),
                'result' => 'ALREADY_CORRECT',
                'reason' => 'CURRENT_INTERVAL_VALID',
                'audit_id' => null,
                'proposed_start' => null,
                'proposed_end' => null,
            ];
        }

        if (! $this->matchesObservedCorruptionFingerprint($meeting)) {
            return $this->skipped($meeting->id, 'CURRENT_FINGERPRINT_MISMATCH', $meeting);
        }

        $sources = AuditLog::query()
            ->where('action', 'meeting.created')
            ->where('target_type', $meeting->getMorphClass())
            ->where('target_id', $meeting->id)
            ->orderBy('id')
            ->get();

        if ($sources->count() !== 1) {
            return $this->skipped($meeting->id, $sources->isEmpty() ? 'NO_EXACT_AUDIT_SOURCE' : 'AMBIGUOUS_AUDIT_SOURCE', $meeting);
        }

        $source = $sources->sole();
        $start = $this->auditTimestamp($source->after['scheduled_start_at'] ?? null);
        $end = $this->auditTimestamp($source->after['scheduled_end_at'] ?? null);

        if (! $start || ! $end) {
            return $this->skipped($meeting->id, 'MISSING_AUDIT_SCHEDULE_METADATA', $meeting);
        }

        if ($end->lte($start)) {
            return $this->skipped($meeting->id, 'INVALID_AUDIT_INTERVAL', $meeting);
        }

        return [
            ...$this->base($meeting),
            'result' => 'ELIGIBLE',
            'reason' => 'AUDIT_BACKED',
            'audit_id' => $source->id,
            'proposed_start' => $start,
            'proposed_end' => $end,
        ];
    }

    private function matchesObservedCorruptionFingerprint(Meeting $meeting): bool
    {
        return $meeting->updated_at !== null
            && $meeting->scheduled_start_at->equalTo($meeting->updated_at->copy()->addHours(7));
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
            'current_start' => $meeting->scheduled_start_at,
            'current_end' => $meeting->scheduled_end_at,
        ];
    }

    /** @return array<string, mixed> */
    private function skipped(?int $meetingId, string $reason, ?Meeting $meeting = null): array
    {
        return [
            'meeting_id' => $meetingId,
            'current_start' => $meeting?->scheduled_start_at,
            'current_end' => $meeting?->scheduled_end_at,
            'result' => 'SKIPPED',
            'reason' => $reason,
            'audit_id' => null,
            'proposed_start' => null,
            'proposed_end' => null,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $plans */
    private function renderPlans(Collection $plans): void
    {
        foreach ($plans as $plan) {
            $this->line(implode(' | ', [
                'meeting='.$plan['meeting_id'],
                'current='.$this->timestamp($plan['current_start']).' to '.$this->timestamp($plan['current_end']),
                'proposed='.$this->timestamp($plan['proposed_start']).' to '.$this->timestamp($plan['proposed_end']),
                'audit='.($plan['audit_id'] ?? '-'),
                'result='.$plan['result'],
                'reason='.$plan['reason'],
            ]));
        }
    }

    /** @param Collection<int, array<string, mixed>> $plans */
    private function summary(Collection $plans, int $writes): void
    {
        $this->newLine();
        $this->info('Summary: eligible='.$plans->where('result', 'ELIGIBLE')->count().', skipped='.$plans->where('result', 'SKIPPED')->count().', already-correct='.$plans->where('result', 'ALREADY_CORRECT')->count().', unrecoverable/not-approved='.$plans->where('reason', 'NOT_APPROVED')->count().', writes='.$writes.'.');
    }

    private function timestamp(?\DateTimeInterface $value): string
    {
        return $value?->toIso8601String() ?? '-';
    }
}
