<?php

namespace App\Actions\Meetings;

use App\Actions\Recordings\StopMeetingRecording;
use App\Enums\MeetingRecordingStopReason;
use App\Models\Meeting;
use App\Models\MeetingRecording;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Records one explicit leave and closes any recording that user started.
 *
 * The application deliberately has no server-side leave of its own before this:
 * leaving a meeting is currently a client-side disconnect plus local teardown, so
 * the only thing the server ever learned was a participant_left webhook. That
 * webhook cannot answer the question this action needs, because a browser refresh
 * and a LiveKit reconnect produce the same event as a person walking out of the
 * room. Inferring an explicit leave from it would end recordings the moment a
 * teacher reloaded the page.
 *
 * So this runs only from the application's explicit Leave control, where a person
 * genuinely asked to leave. Only a recording that person started is stopped: a
 * different teacher leaving has no effect on someone else's capture.
 */
class RecordMeetingLeave
{
    public function __construct(
        private StopMeetingRecording $stop,
        private AuditLogger $audit,
    ) {}

    public function handle(User $actor, Meeting $meeting): ?MeetingRecording
    {
        $recording = MeetingRecording::query()
            ->activeFor($meeting->id)
            ->where('started_by_user_id', $actor->id)
            ->first();

        if (! $recording) {
            return null;
        }

        $this->audit->log('meeting.leave-recorded', $meeting, [], [
            'meeting_id' => $meeting->id,
            'user_id' => $actor->id,
            'recording_id' => $recording->id,
        ]);

        return $this->stop->handle($recording, MeetingRecordingStopReason::RecorderLeft, $actor);
    }
}