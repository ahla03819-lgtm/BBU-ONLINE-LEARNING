<?php

namespace App\Support;

use App\Models\Message;

class MessagePayload
{
    public static function make(Message $message): array
    {
        $message->loadMissing(['sender:id,name', 'replyTo.sender:id,name']);

        return [
            'id' => $message->id,
            'channel_id' => $message->channel_id,
            'client_uuid' => $message->client_uuid,
            'type' => $message->type->value,
            'body' => $message->isHidden() ? null : $message->body,
            'sender' => $message->sender ? ['id' => $message->sender->id, 'name' => $message->sender->name] : null,
            'reply_to' => $message->replyTo ? self::reply($message->replyTo) : null,
            'edited_at' => $message->edited_at?->toISOString(),
            'hidden_at' => $message->hidden_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    private static function reply(Message $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->isHidden() ? null : $message->body,
            'hidden_at' => $message->hidden_at?->toISOString(),
            'sender' => $message->sender ? ['id' => $message->sender->id, 'name' => $message->sender->name] : null,
        ];
    }
}
