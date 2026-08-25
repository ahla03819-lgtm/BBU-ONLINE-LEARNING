<?php

namespace App\Models;

use Database\Factories\ChannelReadStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['channel_id', 'user_id', 'last_read_message_id', 'last_read_at'])]
class ChannelReadState extends Model
{
    /** @use HasFactory<ChannelReadStateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['last_read_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_read_message_id');
    }
}
