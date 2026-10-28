<?php

namespace App\Models;

use Database\Factories\GuestMeetingInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'meeting_id', 'created_by', 'token_hash', 'version', 'expires_at', 'revoked_at', 'rotated_at'])]
class GuestMeetingInvitation extends Model
{
    /** @use HasFactory<GuestMeetingInvitationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (self $invitation) => $invitation->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(GuestMeetingSession::class, 'invitation_id');
    }
}
