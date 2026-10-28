<?php

namespace App\Models;

use App\Enums\GuestMeetingSessionStatus;
use Database\Factories\GuestMeetingSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['public_uuid', 'meeting_id', 'invitation_id', 'meeting_participant_id', 'display_name_snapshot', 'credential_hash', 'status', 'expires_at', 'invalidated_at', 'requested_at', 'decided_at', 'decided_by'])]
class GuestMeetingSession extends Model
{
    /** @use HasFactory<GuestMeetingSessionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (self $session) => $session->public_uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'status' => GuestMeetingSessionStatus::class,
            'expires_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
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

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(GuestMeetingInvitation::class, 'invitation_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The technical LiveKit presence record; it never represents a Laravel User. */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'meeting_participant_id');
    }

    public function participantRemoval(): HasOne
    {
        return $this->hasOne(GuestMeetingParticipantRemoval::class, 'guest_meeting_session_id');
    }
}
