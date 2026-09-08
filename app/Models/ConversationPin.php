<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationPin extends Model
{
    protected $fillable = ['conversation_id', 'conversation_message_id', 'pinned_by_user_id'];
}
