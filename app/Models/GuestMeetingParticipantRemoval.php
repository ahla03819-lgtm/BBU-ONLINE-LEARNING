<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['guest_meeting_session_id', 'meeting_id', 'meeting_participant_id', 'room_name', 'participant_identity', 'attempt_count', 'next_attempt_at', 'last_attempt_at', 'attempt_started_at', 'attempt_token', 'last_failure', 'resolved_at', 'permanently_failed_at'])]
class GuestMeetingParticipantRemoval extends Model
{
    protected function casts(): array
    {
        return [
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'attempt_started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'permanently_failed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(GuestMeetingSession::class, 'guest_meeting_session_id');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'meeting_participant_id');
    }
}
