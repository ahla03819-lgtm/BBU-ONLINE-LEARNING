<?php

namespace App\Actions\Academics;

use App\Enums\AcademicYearStatus;
use App\Models\AcademicYear;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class SaveAcademicYear
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(?AcademicYear $year, array $data): AcademicYear
    {
        return DB::transaction(function () use ($year, $data) {
            AcademicYear::query()->lockForUpdate()->get();
            $before = $year?->toArray() ?? [];
            $active = $data['status'] === AcademicYearStatus::Active->value;
            if ($active) {
                AcademicYear::query()->where('id', '!=', $year?->id)->where('active_slot', 1)->update(['status' => AcademicYearStatus::Closed->value, 'active_slot' => null]);
            } $data['active_slot'] = $active ? 1 : null;
            $year ??= new AcademicYear;
            $year->fill($data)->save();
            $this->audit->log($before ? 'academic-year.updated' : 'academic-year.created', $year, $before, $year->fresh()->toArray());

            return $year;
        });
    }
}
