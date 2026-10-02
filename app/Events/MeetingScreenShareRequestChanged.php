<?php

namespace App\Events;

use App\Models\MeetingScreenShareRequest;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingScreenShareRequestChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public MeetingScreenShareRequest $request) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('meetings.class.'.$this->request->meeting->school_class_id)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.screen-share-request-changed';
    }

    public function broadcastWith(): array
    {
        return ['meeting_uuid' => $this->request->meeting->uuid, 'request' => [
            'reference' => $this->request->public_uuid,
            'status' => $this->request->status->value,
        ]];
    }
}
