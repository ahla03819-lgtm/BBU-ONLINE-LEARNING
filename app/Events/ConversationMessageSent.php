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
        $reply = $this->message->replyTo;

        return ['message' => ['id' => $this->message->id, 'body' => $this->message->body, 'created_at' => $this->message->created_at?->toIso8601String(), 'sender' => ['id' => $sender->id, 'name' => $sender->name, 'avatar_url' => $sender->avatarUrl()], 'reply' => $reply ? ['id' => $reply->id, 'sender_name' => $reply->sender?->name, 'body' => $reply->deleted_at ? 'Message unavailable' : $reply->body, 'unavailable' => $reply->deleted_at !== null] : null, 'attachments' => $this->message->attachments->map(fn ($attachment) => ['uuid' => $attachment->public_uuid, 'name' => $attachment->original_name, 'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes, 'type' => $attachment->attachment_type, 'duration_seconds' => $attachment->duration_seconds, 'url' => route('conversations.attachments.show', [$this->message->conversation, $attachment])])->values()]];
    }
}
