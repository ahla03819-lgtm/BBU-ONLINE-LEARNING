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
 * state-only projection. Viewer permissions stay on the authenticated status
 * endpoint; broadcasting them on a class-wide channel would make one viewer's
 * authority look reusable by every participant. It also never carries a storage
 * path, a disk name or a provider identifier.
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
        $recording = MeetingRecordingProjection::make($this->recording, now());
        unset($recording['can_start'], $recording['can_stop']);

        return [
            'meeting_uuid' => $this->recording->meeting->uuid,
            'recording' => $recording,
        ];
    }
}
