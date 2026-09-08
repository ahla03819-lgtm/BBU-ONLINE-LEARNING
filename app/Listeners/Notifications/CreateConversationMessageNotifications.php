<?php

namespace App\Listeners\Notifications;

use App\Events\ConversationMessageSent;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateConversationMessageNotifications extends NotificationProducer implements ShouldQueue
{
    public function handle(ConversationMessageSent $event): void
    {
        $message = $event->message->fresh(['conversation.members.user', 'sender']);
        $conversation = $message->conversation;
        $recipients = $conversation->members
            ->whereNull('left_at')
            ->pluck('user')
            ->filter(fn (?User $user) => $user && $user->id !== $message->sender_user_id)
            ->values();

        $type = $conversation->type === 'direct'
            ? 'conversation.direct-message'
            : 'conversation.group-message';
        $context = $conversation->type === 'direct'
            ? ['actor_name' => $message->sender->name]
            : ['conversation_name' => $conversation->name];

        foreach ($recipients as $recipient) {
            $this->storeFor(
                collect([$recipient]),
                $type,
                'conversation-message:'.$message->id.':recipient:'.$recipient->id,
                $context,
                $message->sender,
                $conversation,
                'conversations.show',
                ['conversation' => $conversation->public_uuid],
            );
        }
    }
}
