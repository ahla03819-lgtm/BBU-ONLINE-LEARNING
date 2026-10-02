<?php

namespace App\Events;

use App\Models\MeetingRecording;
use App\Support\MeetingRecordingProjection;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Announces that a meeting's recording state changed.
 *
 * It rides the existing meetings.class private channel rather than introducing a
 * new realtime surface, so every participant already authorised for that class
 * receives it with no extra subscription. The payload is deliberately the same
 * authoritative projection the status endpoint returns, and it never carries a
 * storage path, a disk name or a provider identifier: a browser that receives
 * this still has to be authorised by the server to watch anything.
 */
class MeetingRecordingChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public MeetingRecording $recording) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('meetings.class.'.$this->recording->meeting->school_class_id)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.recording-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'meeting_uuid' => $this->recording->meeting->uuid,
            'recording' => MeetingRecordingProjection::make($this->recording, now()),
        ];
    }
}
