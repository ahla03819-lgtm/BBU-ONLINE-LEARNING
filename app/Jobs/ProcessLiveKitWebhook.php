<?php

namespace App\Jobs;

use App\Enums\LiveKitWebhookStatus;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Enums\MeetingStatus;
use App\Events\MeetingEnded;
use App\Events\MeetingScreenShareRequestChanged;
use App\Models\LiveKitWebhookEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
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

    public int $tries = 5;

    public function __construct(public string $eventId) {}

    public function handle(LiveKitRoomManager $rooms, AuditLogger $audit): void
    {
        try {
            DB::transaction(function () use ($rooms, $audit) {
                $event = LiveKitWebhookEvent::query()->lockForUpdate()->find($this->eventId);
                if (! $event || in_array($event->status, [LiveKitWebhookStatus::Processed, LiveKitWebhookStatus::Ignored], true)) {
                    return;
                }
                $event->update(['status' => LiveKitWebhookStatus::Processing, 'attempts' => $event->attempts + 1, 'processing_error' => null]);
                match ($event->event_type) {
                    'participant_joined' => $this->joined($event, $rooms),
                    'participant_left' => $this->left($event, $rooms),
                    'track_published' => $this->trackPublished($event, $rooms),
                    'track_unpublished' => $this->trackUnpublished($event, $rooms),
                    'room_finished' => $this->roomFinished($event, $audit),
                    default => $this->ignored($event),
                };
            });
        } catch (Throwable) {
            LiveKitWebhookEvent::query()->whereKey($this->eventId)->update([
                'status' => LiveKitWebhookStatus::Pending,
                'next_attempt_at' => now()->addMinute(),
                'processing_error' => 'Webhook processing is pending retry.',
            ]);
        }
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
