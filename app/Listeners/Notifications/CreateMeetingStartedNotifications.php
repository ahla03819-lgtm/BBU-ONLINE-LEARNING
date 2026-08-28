<?php

namespace App\Listeners\Notifications;

use App\Events\MeetingStarted;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateMeetingStartedNotifications extends NotificationProducer implements ShouldQueue
{
    public function handle(MeetingStarted $event): void
    {
        $meeting = $event->meeting;
        $this->storeFor(
            $this->recipients->forMeeting($meeting),
            'meeting.started',
            'meeting:'.$meeting->id.':started:'.$meeting->lifecycle_version,
            ['title' => $meeting->title],
            $meeting->host,
            $meeting,
            'meetings.show',
            ['schoolClass' => $meeting->school_class_id, 'meeting' => $meeting->uuid],
        );
    }
}
