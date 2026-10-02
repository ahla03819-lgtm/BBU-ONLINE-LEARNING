<?php

namespace App\Actions\Recordings;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\MeetingRecording;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Publishes exactly one channel card per recording.
 *
 * The card is a Message of type meeting_recording that points at the recording
 * rather than at a file, so moving the recording from Processing to Ready updates
 * this same row and never posts a second message. The unique key on
 * messages.meeting_recording_id enforces that even if two stop paths race past
 * the application check.
 *
 * The target channel is the meeting's own class channel: the subject channel when
 * the meeting is a subject meeting, otherwise the class general channel. That is
 * the same context the meeting itself lives in, so the recording lands where the
 * class already reads.
 */
class PublishMeetingRecordingToChannel
{
    /**
     * Create the card if it does not exist yet.
     *
     * Safe to call more than once for one recording, including from inside a
     * transaction that is already holding the recording's row.
     */
    public function publish(MeetingRecording $recording): ?Message
    {
        if ($recording->channel_id) {
            $existing = Message::query()->where('meeting_recording_id', $recording->id)->first();
            if ($existing) {
                return $existing;
            }

            return $this->create($recording, $recording->channel()->first());
        }

        $channel = $this->resolveChannel($recording);
        if (! $channel) {
            return null;
        }

        $recording->forceFill(['channel_id' => $channel->id])->save();

        return $this->create($recording, $channel);
    }

    /**
     * Re-announce the card after the recording's state changed.
     */
    public function refresh(MeetingRecording $recording): ?Message
    {
        $message = Message::query()->where('meeting_recording_id', $recording->id)->first();
        if (! $message) {
            return $this->publish($recording);
        }

        MessageUpdated::dispatch($message);

        return $message;
    }

    private function create(MeetingRecording $recording, ?Channel $channel): ?Message
    {
        if (! $channel) {
            return null;
        }

        try {
            $message = DB::transaction(function () use ($recording, $channel) {
                return Message::query()->create([
                    'channel_id' => $channel->id,
                    // A recording card is written by the server, so it carries the
                    // same markers as a system message: no sender, no client
                    // reference, and therefore nothing a person can edit or hide.
                    'sender_id' => null,
                    'client_uuid' => null,
                    'type' => MessageType::MeetingRecording,
                    'body' => null,
                    'meeting_recording_id' => $recording->id,
                ]);
            });
        } catch (QueryException) {
            // Another stop path published the card between the check and the
            // insert. The unique key did its job, so adopt the winner.
            return Message::query()->where('meeting_recording_id', $recording->id)->first();
        }

        MessageSent::dispatch($message);

        return $message;
    }

    private function resolveChannel(MeetingRecording $recording): ?Channel
    {
        $recording->loadMissing('meeting.classSubject');

        $meeting = $recording->meeting;
        if (! $meeting) {
            return null;
        }

        if ($classSubject = $meeting->classSubject) {
            $subjectChannel = $classSubject->channel()
                ->where('status', ChannelStatus::Active->value)
                ->first();
            if ($subjectChannel) {
                return $subjectChannel;
            }
        }

        return Channel::query()
            ->where('school_class_id', $meeting->school_class_id)
            ->where('type', ChannelType::General->value)
            ->where('status', ChannelStatus::Active->value)
            ->orderBy('id')
            ->first();
    }
}
