<?php

namespace App\Actions\People;

use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class AssignTeacherToClass
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(TeacherProfile $teacher, SchoolClass $class, string $date): TeacherClassAssignment
    {
        return DB::transaction(function () use ($teacher, $class, $date) {
            $current = TeacherClassAssignment::query()->where('school_class_id', $class->id)->where('current_slot', 1)->lockForUpdate()->first();
            if ($current) {
                $before = $current->toArray();
                $current->update(['ends_on' => $date, 'current_slot' => null]);
                $this->audit->log('teacher-class.ended', $current, $before, $current->fresh()->toArray());
            }$assignment = TeacherClassAssignment::query()->create(['teacher_profile_id' => $teacher->id, 'school_class_id' => $class->id, 'starts_on' => $date, 'current_slot' => 1]);
            $this->audit->log('teacher-class.assigned', $assignment, [], $assignment->toArray());

            return $assignment;
        });
    }
}
