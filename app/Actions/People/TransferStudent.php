<?php

namespace App\Actions\People;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\DB;

class TransferStudent
{
    public function __construct(private EndEnrollment $end, private EnrollStudent $enroll) {}

    public function handle(Enrollment $current, SchoolClass $target, string $date, string $reason): Enrollment
    {
        return DB::transaction(function () use ($current, $target, $date, $reason) {
            if ($current->academic_year_id !== $target->academic_year_id) {
                throw new \DomainException('Transfer class must belong to the same academic year.');
            }$this->end->handle($current, $date, $reason);

            return $this->enroll->handle($current->studentProfile, $target, $date);
        });
    }
}
