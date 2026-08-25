<?php

namespace App\Actions\Messaging;

use App\Events\MessageHidden;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class HideMessage
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Message $message, User $actor): Message
    {
        return DB::transaction(function () use ($message, $actor) {
            $locked = Message::query()->lockForUpdate()->findOrFail($message->id);
            if ($locked->isHidden()) {
                return $locked;
            }
            $locked->update(['hidden_at' => now(), 'hidden_by' => $actor->id]);
            $this->audit->log('message.self_hidden', $locked, [], ['channel_id' => $locked->channel_id, 'hidden_at' => $locked->hidden_at]);
            MessageHidden::dispatch($locked);

            return $locked;
        });
    }
}
