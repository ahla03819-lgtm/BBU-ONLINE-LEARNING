<?php

namespace App\Listeners\Notifications;

use App\Events\MeetingScheduled;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateMeetingScheduledNotifications extends NotificationProducer implements ShouldQueue
{
    public function handle(MeetingScheduled $event): void
    {
        $meeting = $event->meeting;
        $this->storeFor(
            $this->recipients->forMeeting($meeting),
            'meeting.scheduled',
            'meeting:'.$meeting->id.':scheduled:'.$meeting->lifecycle_version,
            ['title' => $meeting->title, 'scheduled_start_at' => $meeting->scheduled_start_at?->toIso8601String()],
            $meeting->creator,
            $meeting,
            'meetings.show',
            ['schoolClass' => $meeting->school_class_id, 'meeting' => $meeting->uuid],
        );
    }
}
