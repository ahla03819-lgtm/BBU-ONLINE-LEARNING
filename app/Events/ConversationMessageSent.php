<?php

namespace App\Events;

use App\Models\ConversationMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public ConversationMessage $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->conversation->public_uuid)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.message.sent';
    }

    public function broadcastWith(): array
    {
        $sender = $this->message->sender;

        return ['message' => ['id' => $this->message->id, 'body' => $this->message->body, 'created_at' => $this->message->created_at?->toIso8601String(), 'sender' => ['id' => $sender->id, 'name' => $sender->name, 'avatar_url' => $sender->avatarUrl()]]];
    }
}
