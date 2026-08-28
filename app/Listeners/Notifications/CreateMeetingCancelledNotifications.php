<?php

namespace App\Listeners\Notifications;

use App\Events\MeetingCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateMeetingCancelledNotifications extends NotificationProducer implements ShouldQueue
{
    public function handle(MeetingCancelled $event): void
    {
        $meeting = $event->meeting;
        $this->storeFor(
            $this->recipients->forMeeting($meeting),
            'meeting.cancelled',
            'meeting:'.$meeting->id.':cancelled:'.$meeting->lifecycle_version,
            ['title' => $meeting->title],
            null,
            $meeting,
            'meetings.show',
            ['schoolClass' => $meeting->school_class_id, 'meeting' => $meeting->uuid],
        );
    }
}
