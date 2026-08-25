<?php

namespace App\Support;

use App\Models\Message;
use App\Models\User;

class ReactionPayload
{
    public static function make(Message $message, ?User $viewer = null): array
    {
        $counts = $message->reactions()->selectRaw('reaction, count(*) as aggregate')->groupBy('reaction')->pluck('aggregate', 'reaction')->map(fn ($count) => (int) $count)->all();

        return ['message_id' => $message->id, 'channel_id' => $message->channel_id, 'version' => (int) $message->reactions_version, 'counts' => $counts, 'current_user' => $viewer ? $message->reactions()->where('user_id', $viewer->id)->value('reaction') : null];
    }
}
