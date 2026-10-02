<?php

namespace App\Actions\Recordings;

use App\Enums\MeetingRecordingProviderStatus;
use App\Enums\MeetingRecordingStatus;
use App\Models\MeetingRecording;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRecordingManager;
use App\Services\Recordings\MeetingRecordingFileStore;
use Illuminate\Support\Facades\DB;

/**
 * Turns a stopped recording into a watchable one.
 *
 * Called both from the provider's egress-ended webhook and from a bounded retry,
 * so a recording reaches Ready without the teacher's browser ever having to be
 * open. It only ever reads what the provider itself reported and what actually
 * exists on the application disk; nothing is taken from a client.
 *
 * Collection is retried while the output is not yet readable, because a provider
 * publishes its file result slightly before the object is available locally. That
 * retry is bounded by the job, and this action fails a recording that never
 * becomes readable, so a card can never sit on Processing forever.
 */
class FinalizeMeetingRecording
{
    public function __construct(
        private LiveKitRecordingManager $provider,
        private MeetingRecordingFileStore $files,
        private PublishMeetingRecordingToChannel $publisher,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{0: ?MeetingRecording, 1: bool} the recording and whether it is still settling
     */
    public function handle(MeetingRecording $recording, ?int $providerDurationSeconds = null): array
    {
        $recording = $recording->fresh();

        if (! $recording || $recording->status->isTerminal()) {
            return [$recording, false];
        }

        $settling = $this->settleStop($recording);
        if ($settling->status !== MeetingRecordingStatus::Processing) {
            return [$settling, false];
        }

        return $this->collect($settling, $providerDurationSeconds);
    }

    /**
     * Move a Stopping recording into Processing once the provider has finished.
     */
    private function settleStop(MeetingRecording $recording): MeetingRecording
    {
        if ($recording->status !== MeetingRecordingStatus::Stopping) {
            return $recording;
        }

        $egressId = $recording->provider_egress_id;
        $providerStatus = is_string($egressId) && $egressId !== ''
            ? $this->provider->inspectRecording($egressId)?->status
            : MeetingRecordingProviderStatus::Complete;

        if ($providerStatus === MeetingRecordingProviderStatus::Failed) {
            return $this->fail($recording, 'The recording provider failed to produce an output file.');
        }

        return DB::transaction(function () use ($recording) {
            $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);
            if ($locked->status !== MeetingRecordingStatus::Stopping) {
                return $locked;
            }

            $before = $locked->only('status');
            $locked->update([
                'status' => MeetingRecordingStatus::Processing,
                'stopped_at' => $locked->stopped_at ?? now(),
            ]);
            $this->audit->log('meeting.recording-processing', $locked, $before, ['recording_id' => $locked->id]);

            return $locked;
        });
    }

    /**
     * @return array{0: MeetingRecording, 1: bool}
     */
    private function collect(MeetingRecording $recording, ?int $providerDurationSeconds): array
    {
        if (! $this->files->outputExists($recording)) {
            $expired = $this->expire($recording);

            return [$expired ?? $recording->fresh(), $expired === null];
        }

        $stored = $this->files->collect($recording, $providerDurationSeconds ?? (int) $recording->duration_seconds);
        if (! $stored) {
            return [$recording, true];
        }

        $ready = DB::transaction(function () use ($recording, $stored) {
            $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);
            if ($locked->status !== MeetingRecordingStatus::Processing) {
                return $locked;
            }

            $before = $locked->only('status');
            $locked->update($stored + [
                'status' => MeetingRecordingStatus::Ready,
                // The active slot is released here and never before, so the meeting
                // can be recorded again only once the previous file is safe.
                'active_slot' => null,
                'ready_at' => now(),
                'failure_reason' => null,
            ]);
            $this->audit->log('meeting.recording-ready', $locked, $before, $locked->only('status', 'duration_seconds', 'size_bytes'));

            return $locked;
        });

        // The card already exists. Advancing its recording re-announces the same
        // message instead of posting a second one.
        $this->publisher->refresh($ready);

        return [$ready->fresh(), false];
    }

    /**
     * Fail a recording whose provider output never became readable.
     *
     * Only reached once the bounded collection window has elapsed, so a slow
     * provider gets a fair chance and an unreachable one still terminates.
     */
    private function expire(MeetingRecording $recording): ?MeetingRecording
    {
        $deadline = $recording->stopped_at?->copy()->addMinutes((int) config('meeting-recordings.collection_deadline_minutes'));
        if (! $deadline || $deadline->isFuture()) {
            return null;
        }

        $failed = DB::transaction(function () use ($recording) {
            $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);
            if ($locked->status !== MeetingRecordingStatus::Processing) {
                return null;
            }

            $before = $locked->only('status');
            $locked->update([
                'status' => MeetingRecordingStatus::Failed,
                'active_slot' => null,
                'ready_at' => now(),
                'failure_reason' => 'The recorded file could not be collected from the recording provider.',
            ]);
            $this->audit->log('meeting.recording-collection-expired', $locked, $before, ['recording_id' => $locked->id]);

            return $locked;
        });

        if ($failed) {
            $this->publisher->refresh($failed);
        }

        return $failed;
    }

    private function fail(MeetingRecording $recording, string $reason): MeetingRecording
    {
        $failed = DB::transaction(function () use ($recording, $reason) {
            $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);
            if ($locked->status->isTerminal()) {
                return $locked;
            }

            $before = $locked->only('status');
            $locked->update([
                'status' => MeetingRecordingStatus::Failed,
                'active_slot' => null,
                'ready_at' => now(),
                'failure_reason' => $reason,
            ]);
            $this->audit->log('meeting.recording-failed', $locked, $before, ['recording_id' => $locked->id]);

            return $locked;
        });

        $this->publisher->refresh($failed);

        return $failed;
    }
}