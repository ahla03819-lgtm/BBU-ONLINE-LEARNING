<?php

namespace App\Actions\Messaging;

use App\Events\MessageUpdated;
use App\Models\Message;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateMessage
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Message $message, string $body): Message
    {
        return DB::transaction(function () use ($message, $body) {
            $locked = Message::query()->lockForUpdate()->findOrFail($message->id);
            abort_if($locked->isHidden() || $locked->isSystem(), 409, 'This message cannot be edited.');
            $locked->update(['body' => $body, 'edited_at' => now()]);
            $this->audit->log('message.edited', $locked, [], ['channel_id' => $locked->channel_id, 'edited_at' => $locked->edited_at]);
            MessageUpdated::dispatch($locked);

            return $locked;
        });
    }
}
