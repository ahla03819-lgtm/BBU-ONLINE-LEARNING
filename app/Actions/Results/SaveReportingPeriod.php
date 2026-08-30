<?php

namespace App\Actions\Results;

use App\Enums\ReportingPeriodStatus;
use App\Models\AcademicYear;
use App\Models\ReportingPeriod;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveReportingPeriod
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(?ReportingPeriod $period, array $data): ReportingPeriod
    {
        return DB::transaction(function () use ($period, $data) {
            $year = AcademicYear::query()->lockForUpdate()->findOrFail($data['academic_year_id']);
            $parent = isset($data['parent_id']) ? ReportingPeriod::query()->lockForUpdate()->find($data['parent_id']) : null;
            if ($parent && $parent->academic_year_id !== $year->id) {
                throw ValidationException::withMessages(['parent_id' => 'The parent reporting period must belong to the selected academic year.']);
            }
            if ($parent && ($data['starts_on'] < $parent->starts_on->toDateString() || $data['ends_on'] > $parent->ends_on->toDateString())) {
                throw ValidationException::withMessages(['starts_on' => 'A child reporting period must fall within its parent date range.']);
            }
            if ($data['starts_on'] < $year->starts_on->toDateString() || $data['ends_on'] > $year->ends_on->toDateString()) {
                throw ValidationException::withMessages(['starts_on' => 'The reporting period must fall within its academic year date range.']);
            }
            if ($period?->exists && $period->status !== ReportingPeriodStatus::Draft) {
                throw ValidationException::withMessages(['reporting_period' => 'Only draft reporting periods can be updated.']);
            }
            $before = $period?->toArray() ?? [];
            $period ??= new ReportingPeriod(['status' => ReportingPeriodStatus::Draft]);
            $period->fill($data)->save();
            $this->audit->log($before ? 'reporting-period.updated' : 'reporting-period.created', $period, $before, $period->fresh()->toArray());

            return $period;
        });
    }
}
