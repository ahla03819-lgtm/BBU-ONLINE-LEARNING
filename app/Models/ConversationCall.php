<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['conversation_id', 'initiated_by_user_id', 'type', 'status', 'livekit_room_name', 'started_at', 'ended_at'])]
class ConversationCall extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $call): void {
            $call->public_uuid ??= (string) Str::uuid();
            $call->livekit_room_name ??= 'bbu_call_'.Str::random(40);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationCallParticipant::class);
    }
}
