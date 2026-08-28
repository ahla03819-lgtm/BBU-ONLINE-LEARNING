<?php

namespace App\Listeners\Notifications;

use App\Events\AssignmentGraded;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateAssignmentGradedNotification extends NotificationProducer implements ShouldQueue
{
    public function handle(AssignmentGraded $event): void
    {
        $grade = $event->grade;
        $grade->loadMissing('submission.assignment.classSubject.schoolClass', 'grader');
        $assignment = $grade->submission->assignment;
        $this->storeFor(
            $this->recipients->forGradedAssignment($grade),
            'assignment.graded',
            'assignment-grade:'.$grade->id.':recorded',
            ['assignment_title' => $assignment->title],
            $grade->grader,
            $grade,
            'coursework.assignments.show',
            ['schoolClass' => $assignment->classSubject->school_class_id, 'classSubject' => $assignment->class_subject_id, 'assignment' => $assignment->id],
        );
    }
}
