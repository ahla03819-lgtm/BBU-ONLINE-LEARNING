<?php

namespace App\Actions\Messaging;

use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SendMessage
{
    public function handle(Channel $channel, User $sender, string $clientUuid, string $body, ?Message $replyTo = null): Message
    {
        $existing = $this->existing($channel, $sender, $clientUuid);
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($channel, $sender, $clientUuid, $body, $replyTo) {
                if ($replyTo && $replyTo->channel_id !== $channel->id) {
                    abort(422, 'The reply target must belong to this channel.');
                }

                $message = Message::query()->create([
                    'channel_id' => $channel->id,
                    'sender_id' => $sender->id,
                    'client_uuid' => $clientUuid,
                    'type' => MessageType::Text,
                    'body' => $body,
                    'reply_to_id' => $replyTo?->id,
                ]);
                MessageSent::dispatch($message);

                return $message;
            });
        } catch (QueryException $exception) {
            $existing = $this->existing($channel, $sender, $clientUuid);
            if ($existing) {
                return $existing;
            }
            throw $exception;
        }
    }

    private function existing(Channel $channel, User $sender, string $clientUuid): ?Message
    {
        return Message::query()->where('channel_id', $channel->id)->where('sender_id', $sender->id)->where('client_uuid', $clientUuid)->first();
    }
}
