<?php

namespace App\Actions\Recordings;

use App\Enums\MeetingRecordingStopReason;
use App\Enums\MeetingRecordingStatus;
use App\Models\MeetingRecording;
use App\Services\AuditLogger;

/**
 * The bounded, idempotent fallback behind every recording deadline.
 *
 * A queued job can be lost, a worker can be down, and a webhook can simply never
 * arrive. None of that may leave a capture running forever or a channel card
 * frozen on Processing, so the same actions the live paths use are re-run from a
 * scheduled sweep:
 *
 *  - a live recording whose scheduled_stop_at has passed is stopped with
 *    DurationReached;
 *  - a recording stuck in Stopping or Processing is finalized;
 *  - anything the sweep changes goes through the identical transitions as a
 *    manual stop, so a sweep and a manual stop racing each other still produce
 *    exactly one provider request and exactly one channel card.
 *
 * Running the sweep twice, or running it after the live path already ran, changes
 * nothing.
 */
class ReconcileMeetingRecordingState
{
    public function __construct(
        private StopMeetingRecording $stop,
        private FinalizeMeetingRecording $finalize,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{stopped: int, finalized: int}
     */
    public function handle(bool $dryRun = false): array
    {
        $now = now();
        $stopped = 0;
        $finalized = 0;

        $overdue = MeetingRecording::query()
            ->where('status', MeetingRecordingStatus::Recording->value)
            ->whereNotNull('scheduled_stop_at')
            ->where('scheduled_stop_at', '<=', $now)
            ->orderBy('id')
            ->get();

        foreach ($overdue as $recording) {
            if ($dryRun) {
                $stopped++;
                continue;
            }
            $before = $recording->status;
            $settled = $this->stop->handle($recording, MeetingRecordingStopReason::DurationReached);
            if ($settled->status !== $before) {
                $stopped++;
                $this->audit->log('meeting.recording-deadline-reconciled', $settled, [], [
                    'recording_id' => $settled->id,
                    'scheduled_stop_at' => $recording->scheduled_stop_at,
                ]);
            }
        }

        $settling = MeetingRecording::query()
            ->whereIn('status', [MeetingRecordingStatus::Stopping->value, MeetingRecordingStatus::Processing->value])
            ->orderBy('id')
            ->get();

        foreach ($settling as $recording) {
            if ($dryRun) {
                $finalized++;
                continue;
            }
            $before = $recording->status;
            [$settled] = $this->finalize->handle($recording);
            if ($settled && $settled->status !== $before) {
                $finalized++;
            }
        }

        return ['stopped' => $stopped, 'finalized' => $finalized];
    }
}
