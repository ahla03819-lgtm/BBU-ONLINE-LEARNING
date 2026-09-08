<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationTyping implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Conversation $conversation, public User $user, public bool $typing) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversation->public_uuid)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.typing';
    }

    public function broadcastWith(): array
    {
        return ['user' => ['id' => $this->user->id, 'name' => $this->user->name], 'typing' => $this->typing];
    }
}
