<?php

namespace App\Events;

use App\Models\Message;
use App\Support\ReactionPayload;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReactionsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('collaboration.channel.'.$this->message->channel_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.reactions.changed';
    }

    public function broadcastWith(): array
    {
        return ['reactions' => ReactionPayload::make($this->message)];
    }
}
