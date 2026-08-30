<?php

namespace App\Jobs;

use App\Enums\LiveKitWebhookStatus;
use App\Enums\MeetingJoinRequestStatus;
use App\Enums\MeetingStatus;
use App\Events\MeetingEnded;
use App\Models\LiveKitWebhookEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRoomManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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
                    'participant_left' => $this->left($event),
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
        MeetingAttendanceSession::query()->firstOrCreate([
            'meeting_participant_id' => $participant->id,
            'livekit_participant_sid' => $event->participant_sid,
        ], ['join_webhook_event_id' => $event->event_id, 'joined_at' => $event->occurred_at]);
        $this->processed($event);
    }

    private function left(LiveKitWebhookEvent $event): void
    {
        $meeting = Meeting::query()->where('livekit_room_name', $event->livekit_room_name)->first();
        $participant = $meeting ? MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('livekit_identity', $event->participant_identity)->lockForUpdate()->first() : null;
        $session = $participant && $event->participant_sid ? $participant->attendanceSessions()->where('livekit_participant_sid', $event->participant_sid)->lockForUpdate()->first() : null;
        if (! $session) {
            $event->update(['status' => LiveKitWebhookStatus::Pending, 'next_attempt_at' => now()->addMinute(), 'processing_error' => 'Waiting for corresponding participant join.']);

            return;
        }
        if (! $session->left_at) {
            $session->update(['left_at' => $event->occurred_at, 'leave_webhook_event_id' => $event->event_id, 'leave_reason' => 'participant_left']);
            $participant->update(['last_left_at' => $event->occurred_at]);
            $meeting->joinRequests()->where('requester_user_id', $participant->user_id)
                ->where('status', MeetingJoinRequestStatus::Admitted->value)
                ->where('decided_at', '<=', $event->occurred_at)
                ->update(['status' => MeetingJoinRequestStatus::Cancelled->value]);
        }
        $this->processed($event);
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
