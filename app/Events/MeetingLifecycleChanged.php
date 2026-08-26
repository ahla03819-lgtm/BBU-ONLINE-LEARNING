<?php

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingLifecycleChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Meeting $meeting, public string $operation) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('meetings.class.'.$this->meeting->school_class_id)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.'.$this->operation;
    }

    public function broadcastWith(): array
    {
        $this->meeting->loadMissing(['host:id,name', 'classSubject.subject:id,code,name']);

        return ['meeting' => [
            'uuid' => $this->meeting->uuid,
            'title' => $this->meeting->title,
            'status' => $this->meeting->status->value,
            'scheduled_start_at' => $this->meeting->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $this->meeting->scheduled_end_at?->toIso8601String(),
            'lifecycle_version' => $this->meeting->lifecycle_version,
            'host' => $this->meeting->host?->only('name'),
            'subject' => $this->meeting->classSubject?->subject?->only('code', 'name'),
        ]];
    }
}
