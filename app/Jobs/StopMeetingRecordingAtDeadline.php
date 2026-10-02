<?php

namespace App\Jobs;

use App\Actions\Recordings\StopMeetingRecording;
use App\Enums\MeetingRecordingStopReason;
use App\Models\MeetingRecording;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The authoritative stop for a recording that has a scheduled deadline.
 *
 * The browser's countdown is a presentation of scheduled_stop_at, never the thing
 * that stops the recording. This job is. It is deliberately idempotent: firing it
 * twice, firing it after a manual stop, or firing it after the meeting ended all
 * resolve to the same no-op, because StopMeetingRecording only lets the caller
 * that moves a live recording into Stopping talk to the provider.
 *
 * The reconciliation sweep remains the backstop if this job is ever lost.
 */
class StopMeetingRecordingAtDeadline implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $recordingId) {}

    public function handle(StopMeetingRecording $stop): void
    {
        $recording = MeetingRecording::query()->find($this->recordingId);
        if (! $recording || ! $recording->status->isLive()) {
            return;
        }

        // A deadline that has not actually arrived is never acted on early, even if
        // the queue ran the job ahead of schedule.
        if ($recording->scheduled_stop_at && $recording->scheduled_stop_at->isFuture()) {
            return;
        }

        $stop->handle($recording, MeetingRecordingStopReason::DurationReached);
    }
}
