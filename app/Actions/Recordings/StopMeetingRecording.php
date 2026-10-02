<?php

namespace App\Actions\Recordings;

use App\Enums\MeetingRecordingProviderStatus;
use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingRecordingStopReason;
use App\Jobs\FinalizeMeetingRecordingOutput;
use App\Models\MeetingRecording;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRecordingManager;
use App\Services\LiveKit\LiveKitRecordingOutcome;
use App\Services\Recordings\MeetingRecordingFileStore;
use Illuminate\Support\Facades\DB;

/**
 * The one place a meeting recording ever stops.
 *
 * Manual stop, the scheduled duration, the recorder explicitly leaving and the
 * host ending the meeting all come through here, so there is a single copy of the
 * lifecycle rules. It is safe to call concurrently with any of them:
 *
 *  - the caller that moves a recording from a live state into Stopping is the
 *    only one that may invoke the provider, which is what makes "the provider was
 *    asked to stop exactly once" a property of the code rather than of timing;
 *  - the channel card is created inside that same transition, so a racing second
 *    caller finds the recording already Stopping and does nothing at all;
 *  - a provider stop that cannot be completed still closes the recording, still
 *    publishes a card that explains the failure, and never leaves the meeting
 *    lifecycle waiting on it.
 */
class StopMeetingRecording
{
    public function __construct(
        private LiveKitRecordingManager $recordings,
        private PublishMeetingRecordingToChannel $publisher,
        private MeetingRecordingFileStore $files,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  User|null  $actor  the teacher who pressed the control, when there is one
     */
    public function handle(MeetingRecording $recording, MeetingRecordingStopReason $reason, ?User $actor = null): MeetingRecording
    {
        [$claimed, $fresh] = $this->claim($recording, $reason, $actor);

        if (! $claimed) {
            return $fresh;
        }

        $outcome = $this->stopProvider($fresh);

        return $this->settle($fresh, $outcome);
    }

    /**
     * Move a live recording into Stopping and publish its processing card.
     *
     * @return array{0: bool, 1: MeetingRecording} whether this caller won the claim
     */
    private function claim(MeetingRecording $recording, MeetingRecordingStopReason $reason, ?User $actor): array
    {
        return DB::transaction(function () use ($recording, $reason, $actor) {
            $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);
            if ($locked->status->isTerminal() || $locked->status->isSettling()) {
                return [false, $locked];
            }

            $before = $locked->only('status', 'stop_reason');
            $locked->update([
                'status' => MeetingRecordingStatus::Stopping,
                'stop_reason' => $reason,
                'stopped_at' => $locked->stopped_at ?? now(),
                'stopped_by_user_id' => $actor?->id ?? $locked->stopped_by_user_id,
            ]);

            $this->publisher->publish($locked);
            $this->audit->log('meeting.recording-stop-requested', $locked, $before, $locked->only('status', 'stop_reason', 'stopped_at'));

            return [true, $locked];
        });
    }

    /**
     * Provider I/O happens outside every lock, so a slow or unreachable provider
     * never holds a database row and never holds up the meeting lifecycle.
     */
    private function stopProvider(MeetingRecording $recording): LiveKitRecordingOutcome
    {
        $egressId = $recording->provider_egress_id;
        if (! is_string($egressId) || $egressId === '') {
            // The provider never confirmed a capture, so there is nothing to stop.
            return new LiveKitRecordingOutcome(MeetingRecordingProviderStatus::Complete);
        }

        return $this->recordings->stopRecording($egressId);
    }

    private function settle(MeetingRecording $recording, LiveKitRecordingOutcome $outcome): MeetingRecording
    {
        if ($outcome->status === MeetingRecordingProviderStatus::Unknown) {
            return DB::transaction(function () use ($recording) {
                $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);
                $locked->update([
                    'status' => MeetingRecordingStatus::Failed,
                    'active_slot' => null,
                    'ready_at' => now(),
                    'failure_reason' => 'The recording provider could not be asked to stop the capture.',
                ]);
                $this->audit->log('meeting.recording-provider-failed', $locked, [], ['recording_id' => $locked->id, 'stop_reason' => $locked->stop_reason?->value]);
                $this->publisher->refresh($locked);

                return $locked;
            });
        }

        if ($outcome->status === MeetingRecordingProviderStatus::Failed) {
            return DB::transaction(function () use ($recording, $outcome) {
                $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);
                $locked->update([
                    'status' => MeetingRecordingStatus::Failed,
                    'stop_reason' => MeetingRecordingStopReason::ProviderFailed,
                    'active_slot' => null,
                    'ready_at' => now(),
                    'failure_reason' => $outcome->error ?: 'The recording provider reported a failure.',
                ]);
                $this->audit->log('meeting.recording-provider-failed', $locked, [], ['recording_id' => $locked->id]);
                $this->publisher->refresh($locked);

                return $locked;
            });
        }

        $settled = DB::transaction(function () use ($recording, $outcome) {
            $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);
            $duration = collect($outcome->files)->max('durationSeconds') ?: 0;
            $locked->update([
                'status' => MeetingRecordingStatus::Processing,
                'stopped_at' => $locked->stopped_at ?? now(),
                'duration_seconds' => $duration > 0 ? $duration : $locked->duration_seconds,
            ]);
            $this->audit->log('meeting.recording-stopped', $locked, [], ['recording_id' => $locked->id, 'stop_reason' => $locked->stop_reason?->value]);

            return $locked;
        });

        // The output may need a moment to become readable on the application disk,
        // so the collection is retried by a bounded job rather than blocking here.
        FinalizeMeetingRecordingOutput::dispatch($settled->id);

        return $settled->fresh();
    }
}