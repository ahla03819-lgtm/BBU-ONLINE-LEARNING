<?php

namespace App\Support;

use App\Models\Message;
use App\Models\User;

class MessagePayload
{
    public static function make(Message $message, ?User $viewer = null): array
    {
        $message->loadMissing(['sender:id,name', 'replyTo.sender:id,name', 'attachments', 'channel:id,school_class_id']);
        $counts = $message->reactions()->selectRaw('reaction, count(*) as aggregate')->groupBy('reaction')->pluck('aggregate', 'reaction')->map(fn ($count) => (int) $count)->all();

        return [
            'id' => $message->id,
            'channel_id' => $message->channel_id,
            'client_uuid' => $message->client_uuid,
            'type' => $message->type->value,
            'body' => $message->isHidden() ? null : $message->body,
            'attachments' => $message->isHidden() ? [] : $message->attachments->map(fn ($attachment) => ['id' => $attachment->id, 'display_name' => $attachment->original_name, 'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes, 'category' => $attachment->category(), 'download_url' => route('collaboration.attachments.download', [$message->channel->school_class_id, $message->channel_id, $message->id, $attachment->id]), 'preview_url' => $attachment->isPreviewable() ? route('collaboration.attachments.preview', [$message->channel->school_class_id, $message->channel_id, $message->id, $attachment->id]) : null])->values()->all(),
            'reactions' => ['version' => (int) $message->reactions_version, 'counts' => $message->isHidden() ? [] : $counts, 'current_user' => $message->isHidden() || ! $viewer ? null : $message->reactions()->where('user_id', $viewer->id)->value('reaction')],
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
