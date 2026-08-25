<?php

namespace App\Models;

use Database\Factories\MeetingAttendanceSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['meeting_participant_id', 'livekit_participant_sid', 'join_webhook_event_id', 'leave_webhook_event_id', 'joined_at', 'left_at', 'leave_reason'])]
class MeetingAttendanceSession extends Model
{
    /** @use HasFactory<MeetingAttendanceSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function meetingParticipant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class);
    }

    public function joinWebhookEvent(): BelongsTo
    {
        return $this->belongsTo(LiveKitWebhookEvent::class, 'join_webhook_event_id', 'event_id');
    }

    public function leaveWebhookEvent(): BelongsTo
    {
        return $this->belongsTo(LiveKitWebhookEvent::class, 'leave_webhook_event_id', 'event_id');
    }
}
