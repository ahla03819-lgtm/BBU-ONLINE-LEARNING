<?php

namespace App\Actions\Recordings;

use App\Enums\MeetingRecordingProviderStatus;
use App\Enums\MeetingRecordingStatus;
use App\Jobs\StopMeetingRecordingAtDeadline;
use App\Models\Meeting;
use App\Models\MeetingRecording;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRecordingManager;
use App\Services\LiveKit\LiveKitRecordingStart;
use App\Services\Recordings\MeetingRecordingFileStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Starts a server-side recording of one meeting.
 *
 * The whole operation is server-authoritative. The deadline is computed once from
 * server time and stored on the row, the authoritative stop is a queued job backed
 * by a reconciliation sweep, and the countdown the browser shows is derived from
 * that stored deadline rather than from a locally reset timer.
 *
 * A refresh, a reconnect or an impatient double click cannot produce a second
 * egress: the row reserves the meeting's single active slot under a lock, and the
 * database refuses a second one regardless.
 */
class StartMeetingRecording
{
    public function __construct(
        private LiveKitRecordingManager $provider,
        private MeetingRecordingFileStore $files,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  int|null  $durationMinutes  null records until manually stopped
     */
    public function handle(User $actor, Meeting $meeting, ?int $durationMinutes): MeetingRecording
    {
        Gate::forUser($actor)->authorize('startRecording', $meeting);

        $recording = $this->claim($actor, $meeting, $durationMinutes);

        $started = $this->provider->startRoomCompositeRecording(
            $meeting->livekit_room_name,
            $this->files->outputPathFor($recording),
            (string) config('meeting-recordings.layout'),
        );

        if (! $started->started()) {
            return $this->abort($recording, $started);
        }

        $active = DB::transaction(function () use ($recording, $started) {
            $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);

            if ($locked->status !== MeetingRecordingStatus::Starting) {
                // The deadline job, an explicit leave or the meeting ending claimed
                // this row while the provider call was in flight. The capture is
                // stopped again immediately so no orphan egress survives.
                return $locked;
            }

            $before = $locked->only('status');
            $locked->update([
                'status' => MeetingRecordingStatus::Recording,
                'provider_egress_id' => $started->egressId,
                'layout' => (string) config('meeting-recordings.layout'),
            ]);
            $this->audit->log('meeting.recording-started', $locked, $before, [
                'recording_id' => $locked->id,
                'started_at' => $locked->started_at,
                'scheduled_stop_at' => $locked->scheduled_stop_at,
            ]);

            if ($locked->scheduled_stop_at) {
                StopMeetingRecordingAtDeadline::dispatch($locked->id)->delay($locked->scheduled_stop_at)->afterCommit();
            }

            return $locked;
        });

        if ($active->status !== MeetingRecordingStatus::Recording && $started->egressId !== null) {
            $this->provider->stopRecording($started->egressId);
        }

        return $active->fresh();
    }

    /**
     * Reserve the meeting's single active recording slot and fix the deadline.
     *
     * started_at and scheduled_stop_at are decided here, from server time, before
     * any provider call, so the deadline cannot drift with a slow provider.
     *
     * @throws ValidationException when the meeting already has an active recording
     */
    private function claim(User $actor, Meeting $meeting, ?int $durationMinutes): MeetingRecording
    {
        try {
            return DB::transaction(function () use ($actor, $meeting, $durationMinutes) {
                Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();

                $startedAt = now();

                $recording = MeetingRecording::query()->create([
                    'meeting_id' => $meeting->id,
                    'started_by_user_id' => $actor->id,
                    'provider' => 'livekit',
                    'status' => MeetingRecordingStatus::Starting,
                    // The slot stays pinned for the whole attempt, so a second Start
                    // cannot slip in between the reservation and the provider's
                    // confirmation.
                    'active_slot' => 1,
                    'started_at' => $startedAt,
                    'scheduled_stop_at' => $durationMinutes === null
                        ? null
                        : $startedAt->copy()->addMinutes($durationMinutes),
                ]);

                // The output path is decided here, from the recording's own public
                // reference, and stored before the provider is told about it. That is
                // what lets collection find the file later without having to trust
                // anything the provider or a client reports back.
                $recording->forceFill([
                    'provider_output_path' => $this->files->outputPathFor($recording),
                ])->save();

                return $recording;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'meeting' => 'This meeting is already being recorded.',
            ]);
        }
    }

    private function abort(MeetingRecording $recording, LiveKitRecordingStart $start): MeetingRecording
    {
        return DB::transaction(function () use ($recording, $start) {
            $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);
            $reason = match ($start->status) {
                MeetingRecordingProviderStatus::Unknown => 'The recording provider could not be reached.',
                MeetingRecordingProviderStatus::Failed => 'The recording provider refused to start this recording.',
                default => 'The recording provider did not confirm the capture.',
            };
            $locked->update([
                'status' => MeetingRecordingStatus::Failed,
                'active_slot' => null,
                'ready_at' => now(),
                'failure_reason' => $start->error ?: $reason,
            ]);
            $this->audit->log('meeting.recording-start-failed', $locked, [], ['recording_id' => $locked->id]);

            return $locked;
        });
    }
}