<?php

namespace App\Jobs;

use App\Actions\Recordings\FinalizeMeetingRecording;
use App\Models\MeetingRecording;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Collects a finished provider output into app-owned storage.
 *
 * A provider publishes its file result slightly before the object is readable, so
 * this retries within a bounded window instead of declaring a recording ready on
 * the strength of a file that is not there yet. When the window runs out the job
 * stops re-queueing itself and the reconciliation sweep takes over, which fails a
 * recording whose output never arrived so its card can say so.
 *
 * Requires no teacher browser: this is what lets a recording reach Ready after the
 * person who started it has closed the tab.
 */
class FinalizeMeetingRecordingOutput implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @var list<int> */
    public array $backoff = [15, 45, 120, 300, 600, 900];

    public function __construct(public int $recordingId, public int $attempt = 1) {}

    public function handle(FinalizeMeetingRecording $finalize): void
    {
        $recording = MeetingRecording::query()->find($this->recordingId);
        if (! $recording || $recording->status->isTerminal()) {
            return;
        }

        [, $settling] = $finalize->handle($recording);

        if (! $settling || $this->attempt >= (int) config('meeting-recordings.collection_attempts')) {
            return;
        }

        $this->dispatchNextAttempt($this->attempt + 1);
    }

    /**
     * Named so it cannot shadow the Dispatchable trait's own dispatch().
     */
    private function dispatchNextAttempt(int $attempt): void
    {
        $backoff = (array) config('meeting-recordings.collection_backoff', $this->backoff);
        $delay = $backoff[$attempt - 2] ?? end($backoff);

        self::dispatch($this->recordingId, $attempt)->delay(now()->addSeconds((int) $delay));
    }
}
