<?php

namespace App\Actions\Results;

use App\Enums\ReportingPeriodStatus;
use App\Models\ReportingPeriod;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionReportingPeriod
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(ReportingPeriod $period, ReportingPeriodStatus $status): ReportingPeriod
    {
        return DB::transaction(function () use ($period, $status) {
            $locked = ReportingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($locked->status === $status) {
                return $locked;
            }
            if (! $locked->status->canTransitionTo($status)) {
                throw ValidationException::withMessages(['status' => "The {$locked->status->value} reporting period cannot transition to {$status->value}."]);
            }
            $before = ['status' => $locked->status->value];
            $locked->update(['status' => $status]);
            $this->audit->log('reporting-period.transitioned', $locked, $before, ['status' => $status->value]);

            return $locked->refresh();
        });
    }
}
