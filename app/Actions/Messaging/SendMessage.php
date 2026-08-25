<?php

namespace App\Actions\Messaging;

use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\Attachments\AttachmentStorage;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SendMessage
{
    public function __construct(private AttachmentStorage $storage, private AuditLogger $audit) {}

    public function handle(Channel $channel, User $sender, string $clientUuid, ?string $body, ?Message $replyTo = null, array $attachments = []): Message
    {
        $existing = $this->existing($channel, $sender, $clientUuid);
        if ($existing) {
            return $existing;
        }

        $stored = [];
        try {
            foreach ($attachments as $candidate) {
                $stored[] = $this->storage->store($channel, $clientUuid, $candidate);
            }
            $message = DB::transaction(function () use ($channel, $sender, $clientUuid, $body, $replyTo, $stored) {
                if ($existing = $this->existing($channel, $sender, $clientUuid)) {
                    return $existing;
                }
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
                foreach ($stored as $item) {
                    $message->attachments()->create(['uploaded_by' => $sender->id, 'client_uuid' => $item['client_uuid'], 'disk' => $item['disk'], 'path' => $item['path'], 'original_name' => $item['original_name'], 'extension' => $item['extension'], 'mime_type' => $item['mime_type'], 'size_bytes' => $item['size_bytes'], 'sha256' => $item['sha256'], 'position' => $item['position']]);
                }
                if ($stored) {
                    $this->audit->log('message.attachments_uploaded', $message, [], ['channel_id' => $channel->id, 'attachment_ids' => $message->attachments()->pluck('id')->all(), 'count' => count($stored), 'mime_types' => array_values(array_unique(array_column($stored, 'mime_type'))), 'size_bytes' => array_sum(array_column($stored, 'size_bytes'))]);
                }
                MessageSent::dispatch($message);

                return $message;
            });
            if ($stored && ! $message->wasRecentlyCreated) {
                $this->storage->deleteMany($stored);
            }

            return $message;
        } catch (QueryException $exception) {
            $this->storage->deleteMany($stored);
            $existing = $this->existing($channel, $sender, $clientUuid);
            if ($existing) {
                return $existing;
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->storage->deleteMany($stored);
            throw $exception;
        }
    }

    private function existing(Channel $channel, User $sender, string $clientUuid): ?Message
    {
        return Message::query()->where('channel_id', $channel->id)->where('sender_id', $sender->id)->where('client_uuid', $clientUuid)->first();
    }
}
