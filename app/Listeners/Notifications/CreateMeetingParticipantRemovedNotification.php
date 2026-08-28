<?php

namespace App\Listeners\Notifications;

use App\Events\MeetingParticipantRemoved;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateMeetingParticipantRemovedNotification extends NotificationProducer implements ShouldQueue
{
    public function handle(MeetingParticipantRemoved $event): void
    {
        $participant = $event->participant;
        $this->storeFor(
            $this->recipients->forRemovedParticipant($participant),
            'meeting.participant-removed',
            'meeting-participant:'.$participant->id.':removed',
            ['message' => 'You were removed from a meeting.'],
            $participant->remover,
            $participant,
        );
    }
}
