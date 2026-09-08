<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ConversationMessageAttachment extends Model
{
    protected $fillable = ['public_uuid', 'disk', 'path', 'original_name', 'extension', 'mime_type', 'size_bytes', 'attachment_type', 'duration_seconds'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'duration_seconds' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $attachment) => $attachment->public_uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }
}
