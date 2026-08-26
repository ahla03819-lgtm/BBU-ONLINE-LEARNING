<?php

namespace App\Models;

use App\Enums\MeetingParticipantRole;
use Database\Factories\MeetingParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_uuid', 'meeting_id', 'user_id', 'livekit_identity', 'display_name_snapshot', 'role', 'join_reserved_until', 'first_joined_at', 'last_left_at', 'removed_at', 'removed_by', 'removal_reason'])]
class MeetingParticipant extends Model
{
    /** @use HasFactory<MeetingParticipantFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (MeetingParticipant $participant): void {
            $participant->public_uuid ??= (string) Str::uuid();
            $participant->livekit_identity ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'role' => MeetingParticipantRole::class,
            'join_reserved_until' => 'datetime',
            'first_joined_at' => 'datetime',
            'last_left_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(MeetingAttendanceSession::class);
    }
}
