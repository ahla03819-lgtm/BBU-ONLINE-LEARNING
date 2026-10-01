<?php

namespace App\Models;

use App\Enums\MeetingScreenShareRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_uuid', 'meeting_id', 'meeting_participant_id', 'requester_user_id', 'decided_by', 'status', 'active_slot', 'requested_at', 'decided_at', 'started_at', 'completed_at', 'expires_at'])]
class MeetingScreenShareRequest extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->public_uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'status' => MeetingScreenShareRequestStatus::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'active_slot' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'meeting_participant_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
