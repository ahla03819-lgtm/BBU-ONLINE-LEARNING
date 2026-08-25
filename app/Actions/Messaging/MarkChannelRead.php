<?php

namespace App\Actions\Messaging;

use App\Models\Channel;
use App\Models\ChannelReadState;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MarkChannelRead
{
    public function handle(Channel $channel, User $user, Message $message): ChannelReadState
    {
        abort_unless($message->channel_id === $channel->id, 422, 'The read cursor must belong to this channel.');

        return DB::transaction(function () use ($channel, $user, $message) {
            $state = ChannelReadState::query()->where('channel_id', $channel->id)->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $state) {
                try {
                    $state = ChannelReadState::query()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
                } catch (QueryException) {
                    $state = ChannelReadState::query()->where('channel_id', $channel->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                }
            }
            if (! $state->last_read_message_id || $message->id > $state->last_read_message_id) {
                $state->update(['last_read_message_id' => $message->id, 'last_read_at' => now()]);
            }

            return $state;
        });
    }
}
