<?php

namespace App\Actions\People;

use App\Models\Enrollment;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class EndEnrollment
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Enrollment $enrollment, string $date, string $reason): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $date, $reason) {
            $locked = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            if (! $locked->isCurrent()) {
                throw new \DomainException('Enrollment has already ended.');
            }$before = $locked->toArray();
            $locked->update(['ended_on' => $date, 'end_reason' => $reason, 'current_slot' => null]);
            $this->audit->log('enrollment.ended', $locked, $before, $locked->fresh()->toArray());

            return $locked;
        });
    }
}
