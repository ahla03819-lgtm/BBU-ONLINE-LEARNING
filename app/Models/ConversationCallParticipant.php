<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_call_id', 'user_id', 'invited_at', 'joined_at', 'left_at', 'declined_at'])]
class ConversationCallParticipant extends Model
{
    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'joined_at' => 'datetime', 'left_at' => 'datetime', 'declined_at' => 'datetime'];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(ConversationCall::class, 'conversation_call_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
