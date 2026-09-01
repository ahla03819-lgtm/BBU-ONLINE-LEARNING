<?php

namespace App\Actions\Classes;

use App\Models\SchoolClass;
use App\Services\AuditLogger;
use App\Services\ClassJoinCode;
use Illuminate\Support\Facades\DB;

class ManageSchoolClassJoinCode
{
    public function __construct(private ClassJoinCode $codes, private AuditLogger $audit) {}

    public function regenerate(SchoolClass $schoolClass): SchoolClass
    {
        return $this->update($schoolClass, true, true, 'school-class.join-code.regenerated');
    }

    public function enable(SchoolClass $schoolClass): SchoolClass
    {
        return $this->update($schoolClass, true, false, 'school-class.join-code.enabled');
    }

    public function disable(SchoolClass $schoolClass): SchoolClass
    {
        return $this->update($schoolClass, false, false, 'school-class.join-code.disabled');
    }

    private function update(SchoolClass $schoolClass, bool $enabled, bool $regenerate, string $action): SchoolClass
    {
        return DB::transaction(function () use ($schoolClass, $enabled, $regenerate, $action) {
            $locked = SchoolClass::query()->lockForUpdate()->findOrFail($schoolClass->id);
            $before = $this->auditState($locked);

            $locked->forceFill([
                'join_code' => $regenerate || ! $locked->join_code ? $this->codes->next() : $locked->join_code,
                'join_code_enabled' => $enabled,
            ])->save();

            $this->audit->log($action, $locked, $before, $this->auditState($locked));

            return $locked;
        });
    }

    private function auditState(SchoolClass $schoolClass): array
    {
        return [
            'join_code_present' => (bool) $schoolClass->join_code,
            'join_code_enabled' => (bool) $schoolClass->join_code_enabled,
        ];
    }
}
