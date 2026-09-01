<?php

namespace App\Events;

use App\Models\ConversationCall;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationCallSignal implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public ConversationCall $call, public string $signal) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('conversation-call.'.$this->call->public_uuid)];
        if ($this->signal === 'started') {
            foreach ($this->call->participants()->where('user_id', '!=', $this->call->initiated_by_user_id)->whereNull('declined_at')->pluck('user_id') as $userId) {
                $channels[] = new PrivateChannel('incoming-call.'.$userId);
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'conversation.call.'.$this->signal;
    }

    public function broadcastWith(): array
    {
        return ['call' => ['uuid' => $this->call->public_uuid, 'conversation_uuid' => $this->call->conversation->public_uuid, 'type' => $this->call->type, 'status' => $this->call->status, 'initiator' => ['id' => $this->call->initiator->id, 'name' => $this->call->initiator->name, 'avatar_url' => $this->call->initiator->avatarUrl()]]];
    }
}
