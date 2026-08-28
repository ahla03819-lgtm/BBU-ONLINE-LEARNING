<?php

namespace App\Listeners\Notifications;

use App\Events\AssignmentPublished;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateAssignmentPublishedNotifications extends NotificationProducer implements ShouldQueue
{
    public function handle(AssignmentPublished $event): void
    {
        $assignment = $event->assignment;
        $assignment->loadMissing('classSubject.schoolClass');
        $this->storeFor(
            $this->recipients->forPublishedAssignment($assignment),
            'assignment.published',
            'assignment:'.$assignment->id.':published:'.$assignment->lifecycle_version,
            ['title' => $assignment->title],
            $assignment->creator,
            $assignment,
            'coursework.assignments.show',
            ['schoolClass' => $assignment->classSubject->school_class_id, 'classSubject' => $assignment->class_subject_id, 'assignment' => $assignment->id],
        );
    }
}
