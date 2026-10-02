<?php

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['channel_id', 'sender_id', 'client_uuid', 'type', 'body', 'reply_to_id', 'meeting_recording_id', 'edited_at', 'hidden_at', 'hidden_by', 'hidden_reason', 'reactions_version'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['type' => MessageType::class, 'edited_at' => 'datetime', 'hidden_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_id');
    }

    public function hider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class)->orderBy('position');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function meetingRecording(): BelongsTo
    {
        return $this->belongsTo(MeetingRecording::class, 'meeting_recording_id');
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isSystem(): bool
    {
        return $this->type === MessageType::System;
    }

    /**
     * A recording card is written and advanced by the server, never by a person.
     *
     * It is excluded from the same edit and hide affordances as a system message
     * so a teacher cannot retitle the authoritative record of what the provider
     * produced.
     */
    public function isServerAuthored(): bool
    {
        return $this->isSystem() || $this->type === MessageType::MeetingRecording;
    }
}
