<?php

namespace App\Models;

use App\Enums\LiveKitWebhookStatus;
use Database\Factories\LiveKitWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['event_id', 'event_type', 'livekit_room_name', 'participant_identity', 'participant_sid', 'occurred_at', 'payload_sha256', 'status', 'attempts', 'next_attempt_at', 'processed_at', 'processing_error'])]
class LiveKitWebhookEvent extends Model
{
    /** @use HasFactory<LiveKitWebhookEventFactory> */
    use HasFactory;

    protected $table = 'livekit_webhook_events';

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'status' => LiveKitWebhookStatus::class,
            'attempts' => 'integer',
            'occurred_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function joinedAttendanceSessions(): HasMany
    {
        return $this->hasMany(MeetingAttendanceSession::class, 'join_webhook_event_id', 'event_id');
    }

    public function leftAttendanceSessions(): HasMany
    {
        return $this->hasMany(MeetingAttendanceSession::class, 'leave_webhook_event_id', 'event_id');
    }
}
