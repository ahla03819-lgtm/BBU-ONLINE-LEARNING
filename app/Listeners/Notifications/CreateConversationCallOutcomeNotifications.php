<?php

namespace App\Listeners\Notifications;

use App\Events\ConversationCallSignal;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateConversationCallOutcomeNotifications extends NotificationProducer implements ShouldQueue
{
    public function handle(ConversationCallSignal $event): void
    {
        if (! in_array($event->signal, ['declined', 'cancelled'], true)) {
            return;
        }

        $call = $event->call->fresh(['conversation.members.user', 'initiator', 'participants.user']);
        if (! $call || $call->conversation->type !== 'direct') {
            return;
        }

        $other = $call->participants
            ->first(fn ($participant) => $participant->user_id !== $call->initiated_by_user_id)?->user;
        if (! $other) {
            return;
        }

        if ($event->signal === 'declined') {
            $actor = $other;
            $recipient = $call->initiator;
            $type = 'conversation.call-declined';
            $context = ['actor_name' => $actor->name, 'call_type' => $call->type];
        } else {
            $actor = $call->initiator;
            $recipient = $other;
            $type = 'conversation.call-missed';
            $context = ['actor_name' => $actor->name, 'call_type' => $call->type];
        }

        $this->storeFor(
            collect([$recipient]),
            $type,
            'conversation-call:'.$call->id.':'.$event->signal.':recipient:'.$recipient->id,
            $context,
            $actor,
            $call,
            'conversations.show',
            ['conversation' => $call->conversation->public_uuid],
        );
    }
}
