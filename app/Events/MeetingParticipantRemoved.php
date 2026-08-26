<?php

namespace App\Events;

use App\Models\MeetingParticipant;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingParticipantRemoved implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public MeetingParticipant $participant) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('meetings.class.'.$this->participant->meeting->school_class_id)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.participant-removed';
    }

    public function broadcastWith(): array
    {
        return ['participant' => [
            'reference' => $this->participant->public_uuid,
            'meeting_uuid' => $this->participant->meeting->uuid,
            'removed' => true,
        ], 'lifecycle_version' => $this->participant->meeting->lifecycle_version];
    }
}
