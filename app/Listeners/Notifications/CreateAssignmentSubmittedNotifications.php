<?php

namespace App\Listeners\Notifications;

use App\Events\AssignmentSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateAssignmentSubmittedNotifications extends NotificationProducer implements ShouldQueue
{
    public function handle(AssignmentSubmitted $event): void
    {
        $revision = $event->revision;
        $revision->loadMissing('submission.assignment.classSubject.schoolClass', 'author');
        $assignment = $revision->submission->assignment;
        $this->storeFor(
            $this->recipients->forSubmittedAssignment($revision),
            'assignment.submitted',
            'assignment-submission-revision:'.$revision->id.':submitted',
            ['assignment_title' => $assignment->title],
            $revision->author,
            $revision->submission,
            'coursework.assignments.show',
            ['schoolClass' => $assignment->classSubject->school_class_id, 'classSubject' => $assignment->class_subject_id, 'assignment' => $assignment->id],
        );
    }
}
