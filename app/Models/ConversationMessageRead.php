<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationMessageRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['conversation_message_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
