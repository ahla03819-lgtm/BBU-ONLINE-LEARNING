<?php

namespace App\Jobs;

use App\Actions\Recordings\FinalizeMeetingRecording;
use App\Actions\Recordings\StopMeetingRecording;
use App\Enums\LiveKitWebhookStatus;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingRecordingStopReason;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Enums\MeetingStatus;
use App\Events\MeetingEnded;
use App\Events\MeetingRecordingChanged;
use App\Events\MeetingScreenShareRequestChanged;
use App\Models\LiveKitWebhookEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
use App\Models\MeetingRecording;
use App\Models\MeetingScreenShareRequest;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRoomManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livekit\TrackSource;
use RuntimeException;
use Throwable;

class ProcessLiveKitWebhook implements ShouldQueue
{
    use Queueable;

    /** Egress lifecycle events carry a recording's completion rather than presence. */
    private const EGRESS_EVENTS = ['egress_started', 'egress_updated', 'egress_ended'];

    public int $tries = 5;

    public function __construct(public string $eventId) {}

    /**
     * $finalize is optional so the existing two-argument calls that drive this job
     * directly, none of which involve a recording, keep working unchanged.
     */
    public function handle(LiveKitRoomManager $rooms, AuditLogger $audit, ?FinalizeMeetingRecording $finalize = null): void
    {
        $finalize ??= app(FinalizeMeetingRecording::class);

        try {
            $egressEvent = DB::transaction(function () use ($rooms, $audit) {
                $event = LiveKitWebhookEvent::query()->lockForUpdate()->find($this->eventId);
                if (! $event || in_array($event->status, [LiveKitWebhookStatus::Processed, LiveKitWebhookStatus::Ignored], true)) {
                    return null;
                }
                $event->update(['status' => LiveKitWebhookStatus::Processing, 'attempts' => $event->attempts + 1, 'processing_error' => null]);

                if (in_array($event->event_type, self::EGRESS_EVENTS, true)) {
                    // Nothing is mutated under this lock. Finalising a recording asks
                    // the provider about the egress and opens its own transactions,
                    // which must never happen while a webhook row is held.
                    return $event;
                }

                match ($event->event_type) {
                    'participant_joined' => $this->joined($event, $rooms),
                    'participant_left' => $this->left($event, $rooms),
                    'track_published' => $this->trackPublished($event, $rooms),
                    'track_unpublished' => $this->trackUnpublished($event, $rooms),
                    'room_finished' => $this->roomFinished($event, $audit),
                    default => $this->ignored($event),
                };

                return null;
            });

            if ($egressEvent) {
                $this->egress($egressEvent, $finalize, $audit);
            }
        } catch (Throwable) {
            LiveKitWebhookEvent::query()->whereKey($this->eventId)->update([
                'status' => LiveKitWebhookStatus::Pending,
                'next_attempt_at' => now()->addMinute(),
                'processing_error' => 'Webhook processing is pending retry.',
            ]);
        }
    }

    /**
     * Apply one verified Egress lifecycle event.
     *
     * The event only says which egress changed and that it changed; the truth about
     * the egress itself is re-read from the provider, so an egress the application
     * has never recorded is simply ignored rather than being trusted into a state.
     */
    private function egress(LiveKitWebhookEvent $event, FinalizeMeetingRecording $finalize, AuditLogger $audit): void
    {
        $recording = $event->egress_id
            ? MeetingRecording::query()->where('provider_egress_id', $event->egress_id)->orderByDesc('id')->first()
            : null;

        if (! $recording) {
            $this->ignored($event);

            return;
        }

        match ($event->event_type) {
            // A start whose HTTP response never reached the browser still leaves a
            // row in Starting. The provider saying it started is what closes that gap.
            'egress_started' => $this->recordingStarted($recording),
            'egress_ended' => $this->recordingEnded($recording, $finalize, $audit),
            // Live layout and output changes do not alter the recording's own
            // lifecycle, so an update is acknowledged and nothing else.
            default => null,
        };

        $this->processed($event);
    }

    private function recordingStarted(MeetingRecording $recording): void
    {
        DB::transaction(function () use ($recording) {
            $locked = MeetingRecording::query()->lockForUpdate()->find($recording->id);
            if ($locked && $locked->status === MeetingRecordingStatus::Starting) {
                $locked->update(['status' => MeetingRecordingStatus::Recording]);
            }
        });
    }

    private function recordingEnded(MeetingRecording $recording, FinalizeMeetingRecording $finalize, AuditLogger $audit): void
    {
        [$settled, $stillSettling] = $finalize->handle($recording);

        if ($stillSettling) {
            // The provider has finished but the output is not readable yet. The
            // bounded collection job owns that wait, so this event is done.
            FinalizeMeetingRecordingOutput::dispatch($settled->id);
        }

        MeetingRecordingChanged::dispatch($settled);
        $audit->log('meeting.recording-egress-ended', $settled, [], ['recording_id' => $settled->id]);
    }

    private function joined(LiveKitWebhookEvent $event, LiveKitRoomManager $rooms): void
    {
        $meeting = Meeting::query()->where('livekit_room_name', $event->livekit_room_name)->first();
        $participant = $meeting ? MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('livekit_identity', $event->participant_identity)->lockForUpdate()->first() : null;
        if (! $meeting || ! $participant || ! $event->participant_sid) {
            $this->ignored($event);

            return;
        }
        if ($participant->removed_at) {
            $rooms->removeParticipant($meeting->livekit_room_name, $participant->livekit_identity);
            $this->processed($event);

            return;
        }
        $participant->update(['join_reserved_until' => null, 'first_joined_at' => $participant->first_joined_at ?? $event->occurred_at]);
        // Keyed on the provider participant SID, which is also the key presence
        // reconciliation uses, so a webhook that arrives after a reconciled row
        // adopts that row instead of opening a second session for one presence
        // episode. The reconciled row already carries the provider's own joined
        // timestamp, which is more authoritative than the webhook delivery time,
        // so joined_at is never overwritten.
        $session = MeetingAttendanceSession::query()
            ->where('meeting_participant_id', $participant->id)
            ->where('livekit_participant_sid', $event->participant_sid)
            ->lockForUpdate()
            ->first();

        if ($session) {
            if ($session->join_webhook_event_id === null) {
                $session->update(['join_webhook_event_id' => $event->event_id]);
            }
        } else {
            MeetingAttendanceSession::query()->create([
                'meeting_participant_id' => $participant->id,
                'livekit_participant_sid' => $event->participant_sid,
                'join_webhook_event_id' => $event->event_id,
                'joined_at' => $event->occurred_at,
                'left_at' => null,
            ]);
        }
        $this->processed($event);
    }

    private function left(LiveKitWebhookEvent $event, LiveKitRoomManager $rooms): void
    {
        $meeting = Meeting::query()->where('livekit_room_name', $event->livekit_room_name)->first();
        $participant = $meeting ? MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('livekit_identity', $event->participant_identity)->lockForUpdate()->first() : null;
        if ($meeting && $participant) {
            $this->revokeStudentScreenShare($meeting, $participant, $rooms);
        }
        $session = $participant && $event->participant_sid ? $participant->attendanceSessions()->where('livekit_participant_sid', $event->participant_sid)->lockForUpdate()->first() : null;
        if (! $session) {
            $event->update(['status' => LiveKitWebhookStatus::Pending, 'next_attempt_at' => now()->addMinute(), 'processing_error' => 'Waiting for corresponding participant join.']);

            return;
        }
        if (! $session->left_at) {
            $session->update(['left_at' => $event->occurred_at, 'leave_webhook_event_id' => $event->event_id, 'leave_reason' => 'participant_left']);
            $participant->update(['last_left_at' => $event->occurred_at]);
        }
        $this->processed($event);
    }

    private function trackUnpublished(LiveKitWebhookEvent $event, LiveKitRoomManager $rooms): void
    {
        // SCREEN_SHARE video is the canonical lifecycle track. Its audio
        // companion can stop on its own (share-audio toggle, device change)
        // while the screen itself keeps going, so it must never end the session.
        if ($event->track_source !== TrackSource::SCREEN_SHARE) {
            $this->ignored($event);

            return;
        }
        $meeting = Meeting::query()->where('livekit_room_name', $event->livekit_room_name)->first();
        $participant = $meeting ? MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('livekit_identity', $event->participant_identity)->lockForUpdate()->first() : null;
        if (! $meeting || ! $participant) {
            $this->ignored($event);

            return;
        }
        $this->revokeStudentScreenShare($meeting, $participant, $rooms);
        $this->processed($event);
    }

    private function trackPublished(LiveKitWebhookEvent $event, LiveKitRoomManager $rooms): void
    {
        // Only the canonical screen-share video track drives the lifecycle.
        if ($event->track_source !== TrackSource::SCREEN_SHARE) {
            $this->ignored($event);

            return;
        }
        $meeting = Meeting::query()->where('livekit_room_name', $event->livekit_room_name)->first();
        $participant = $meeting ? MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('livekit_identity', $event->participant_identity)->lockForUpdate()->first() : null;
        $request = $participant?->screenShareRequests()
            ->where('active_slot', 1)
            ->whereIn('status', [MeetingScreenShareRequestStatus::Approved->value, MeetingScreenShareRequestStatus::Sharing->value])
            ->lockForUpdate()->first();
        if (! $request) {
            $this->ignored($event);

            return;
        }
        if ($request->status->isSharing()) {
            $this->processed($event);

            return;
        }
        // The approval window is a real deadline, not just a scheduled job: a
        // publication that arrives after it lapsed must never be legitimised.
        if ($request->expires_at?->isPast()) {
            $this->expireLapsedPublication($event, $request, $rooms);

            return;
        }
        $request->update([
            'status' => MeetingScreenShareRequestStatus::Sharing,
            'started_at' => $request->started_at ?? $event->occurred_at,
            'expires_at' => null,
        ]);
        MeetingScreenShareRequestChanged::dispatch($request);
        $this->processed($event);
    }

    /**
     * The approval had already lapsed when the track was published, so the
     * publication is unauthorised. The permission grant is withdrawn and the
     * already-published track is muted by its verified server-side SID. LiveKit
     * exposes no operation that unpublishes a track, so the muted publication
     * persists until the participant stops it or the host removes them.
     */
    private function expireLapsedPublication(LiveKitWebhookEvent $event, MeetingScreenShareRequest $request, LiveKitRoomManager $rooms): void
    {
        $roomName = $request->meeting->livekit_room_name;
        $identity = $request->participant->livekit_identity;
        if ($rooms->setParticipantScreenSharePermission($roomName, $identity, false) === MeetingProviderState::Unknown) {
            throw new RuntimeException('Lapsed screen sharing approval revocation is pending retry.');
        }
        if ($event->track_sid && $rooms->mutePublishedTrack($roomName, $identity, $event->track_sid) === MeetingProviderState::Unknown) {
            Log::warning('Lapsed screen sharing approval could not be muted; the participant must stop it or be removed.', [
                'room' => $roomName, 'identity' => $identity, 'request_id' => $request->id,
            ]);
        }
        $request->update([
            'status' => MeetingScreenShareRequestStatus::Expired,
            'active_slot' => null,
            'completed_at' => now(),
            'expires_at' => null,
        ]);
        MeetingScreenShareRequestChanged::dispatch($request);
        $this->processed($event);
    }

    private function revokeStudentScreenShare(Meeting $meeting, MeetingParticipant $participant, LiveKitRoomManager $rooms): void
    {
        $request = $participant->screenShareRequests()->where('active_slot', 1)->lockForUpdate()->first();
        if (! $request) {
            return;
        }
        if ($rooms->setParticipantScreenSharePermission($meeting->livekit_room_name, $participant->livekit_identity, false) === MeetingProviderState::Unknown) {
            throw new RuntimeException('Screen sharing permission revocation is pending retry.');
        }
        $status = $request->status === MeetingScreenShareRequestStatus::Pending
            ? MeetingScreenShareRequestStatus::Cancelled
            : MeetingScreenShareRequestStatus::Consumed;
        $request->update(['status' => $status, 'active_slot' => null, 'completed_at' => now(), 'expires_at' => null]);
        MeetingScreenShareRequestChanged::dispatch($request);
    }

    private function roomFinished(LiveKitWebhookEvent $event, AuditLogger $audit): void
    {
        $meeting = Meeting::query()->where('livekit_room_name', $event->livekit_room_name)->lockForUpdate()->first();
        if (! $meeting) {
            $this->ignored($event);

            return;
        }
        MeetingAttendanceSession::query()->whereHas('meetingParticipant', fn ($query) => $query->where('meeting_id', $meeting->id))->whereNull('left_at')->update(['left_at' => $event->occurred_at, 'leave_reason' => 'room_finished']);
        $meeting->participants()->update(['join_reserved_until' => null]);
        $meeting->screenShareRequests()->whereNotNull('active_slot')->get()->each(function ($request) {
            $request->update(['status' => MeetingScreenShareRequestStatus::Expired, 'active_slot' => null, 'completed_at' => now()]);
            MeetingScreenShareRequestChanged::dispatch($request);
        });
        if (in_array($meeting->status, [MeetingStatus::Active, MeetingStatus::Ending], true)) {
            $before = $meeting->only('status', 'lifecycle_version');
            $meeting->update(['status' => MeetingStatus::Ended, 'actual_end_at' => $meeting->actual_end_at ?? $event->occurred_at, 'lifecycle_version' => $meeting->lifecycle_version + 1, 'last_provider_error' => null]);
            $audit->log('meeting.provider-finished', $meeting, $before, $meeting->only('status', 'lifecycle_version', 'actual_end_at'));
            MeetingEnded::dispatch($meeting);
        }
        $this->processed($event);

        // A room that finished on its own still owes the class a recording. This
        // runs after the event is marked processed and outside its transaction,
        // because the stop asks the provider and opens its own; it is idempotent,
        // so an End meeting that already stopped the recording is a no-op here.
        $this->stopRecordingForFinishedRoom($meeting);
    }

    private function stopRecordingForFinishedRoom(Meeting $meeting): void
    {
        $recording = MeetingRecording::query()->activeFor($meeting->id)->first();
        if (! $recording) {
            return;
        }

        try {
            $stopped = app(StopMeetingRecording::class)->handle($recording, MeetingRecordingStopReason::MeetingEnded);
            MeetingRecordingChanged::dispatch($stopped);
        } catch (Throwable $error) {
            Log::warning('Meeting recording could not be closed after the provider finished the room.', [
                'meeting_id' => $meeting->id,
                'recording_id' => $recording->id,
                'exception_class' => $error::class,
            ]);
        }
    }

    private function processed(LiveKitWebhookEvent $event): void
    {
        $event->update(['status' => LiveKitWebhookStatus::Processed, 'processed_at' => now(), 'next_attempt_at' => null, 'processing_error' => null]);
    }

    private function ignored(LiveKitWebhookEvent $event): void
    {
        $event->update(['status' => LiveKitWebhookStatus::Ignored, 'processed_at' => now(), 'next_attempt_at' => null, 'processing_error' => null]);
    }
}
