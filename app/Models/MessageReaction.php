<?php

namespace App\Models;

use App\Enums\ReactionType;
use Database\Factories\MessageReactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['message_id', 'user_id', 'reaction'])]
class MessageReaction extends Model
{
    /** @use HasFactory<MessageReactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['reaction' => ReactionType::class];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
