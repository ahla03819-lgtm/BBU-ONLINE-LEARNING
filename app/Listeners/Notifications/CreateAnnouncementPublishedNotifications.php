<?php

namespace App\Listeners\Notifications;

use App\Events\AnnouncementPublished;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateAnnouncementPublishedNotifications extends NotificationProducer implements ShouldQueue
{
    public function handle(AnnouncementPublished $event): void
    {
        $announcement = $event->announcement;
        $announcement->loadMissing('channel.schoolClass');
        $this->storeFor(
            $this->recipients->forAnnouncement($announcement),
            'announcement.published',
            'announcement:'.$announcement->id.':published:'.$announcement->published_at?->getTimestamp(),
            ['title' => $announcement->title],
            $announcement->author,
            $announcement,
            'collaboration.channels.show',
            ['schoolClass' => $announcement->channel->school_class_id, 'channel' => $announcement->channel_id],
        );
    }
}
