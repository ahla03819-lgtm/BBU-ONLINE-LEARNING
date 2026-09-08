<?php

namespace App\Listeners\Notifications;

use App\Events\ConversationMembershipChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateConversationMembershipNotifications extends NotificationProducer implements ShouldQueue
{
    public function handle(ConversationMembershipChanged $event): void
    {
        $member = $event->member->fresh('user');
        $conversation = $event->conversation->fresh();
        $recipient = $member->user;
        if (! $recipient || ! $conversation || ! in_array($event->change, ['added', 'removed', 'promoted'], true)) {
            return;
        }

        $type = 'conversation.member-'.$event->change;
        $hasAccess = $event->change !== 'removed';
        $context = $hasAccess
            ? ['conversation_name' => $conversation->name]
            : ['message' => 'Your access to a group conversation changed.'];

        $this->storeFor(
            collect([$recipient]),
            $type,
            'conversation-membership:'.$conversation->id.':member:'.$member->id.':'.$event->change.':'.$member->updated_at?->getTimestamp(),
            $context,
            $event->actor,
            $conversation,
            $hasAccess ? 'conversations.show' : null,
            $hasAccess ? ['conversation' => $conversation->public_uuid] : [],
        );
    }
}
