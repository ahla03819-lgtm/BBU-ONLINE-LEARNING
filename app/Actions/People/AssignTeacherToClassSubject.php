<?php

namespace App\Actions\People;

use App\Enums\ClassSubjectStatus;
use App\Models\ClassSubject;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class AssignTeacherToClassSubject
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(TeacherProfile $teacher, ClassSubject $subject, string $date): TeacherClassSubjectAssignment
    {
        $subject->refresh();
        if ($subject->status !== ClassSubjectStatus::Active) {
            throw new \DomainException('Teachers cannot be assigned to an archived class subject.');
        }

        return DB::transaction(function () use ($teacher, $subject, $date) {
            $current = TeacherClassSubjectAssignment::query()->where('class_subject_id', $subject->id)->where('current_slot', 1)->lockForUpdate()->first();
            if ($current) {
                $before = $current->toArray();
                $current->update(['ends_on' => $date, 'current_slot' => null]);
                $this->audit->log('teacher-class-subject.ended', $current, $before, $current->fresh()->toArray());
            }$assignment = TeacherClassSubjectAssignment::query()->create(['teacher_profile_id' => $teacher->id, 'class_subject_id' => $subject->id, 'starts_on' => $date, 'current_slot' => 1]);
            $this->audit->log('teacher-class-subject.assigned', $assignment, [], $assignment->toArray());

            return $assignment;
        });
    }
}
