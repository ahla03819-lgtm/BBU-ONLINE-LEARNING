<?php

namespace App\Actions\Messaging;

use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class CreateSystemMessage
{
    public function handle(Channel $channel, string $body): Message
    {
        return DB::transaction(function () use ($channel, $body) {
            $message = Message::query()->create(['channel_id' => $channel->id, 'sender_id' => null, 'client_uuid' => null, 'type' => MessageType::System, 'body' => $body]);
            MessageSent::dispatch($message);

            return $message;
        });
    }
}
