<?php

namespace App\Actions\People;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class EnrollStudent
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(StudentProfile $student, SchoolClass $class, string $date): Enrollment
    {
        return DB::transaction(function () use ($student, $class, $date) {
            StudentProfile::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            if ($student->enrollments()->where('school_class_id', $class->id)->where('current_slot', 1)->exists()) {
                throw new \DomainException('Student already has a current enrollment in this class.');
            }

            $enrollment = $student->enrollments()->create(['academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'enrolled_on' => $date, 'current_slot' => 1]);
            $this->audit->log('enrollment.created', $enrollment, [], $enrollment->toArray());

            return $enrollment;
        });
    }
}
