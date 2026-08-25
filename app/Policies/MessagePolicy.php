<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\CollaborationAccess;

class MessagePolicy
{
    public function __construct(private CollaborationAccess $access) {}

    public function viewAny(User $user, Channel $channel): bool
    {
        return $user->can('messages.view') && $this->access->canAccessChannel($user, $channel);
    }

    public function view(User $user, Message $message): bool
    {
        return $this->viewAny($user, $message->channel);
    }

    public function create(User $user, Channel $channel): bool
    {
        return $user->can('messages.create')
            && $channel->status->value === 'active'
            && $this->access->isAcademicallyActive($channel->schoolClass)
            && $this->access->canAccessChannel($user, $channel);
    }

    public function update(User $user, Message $message): bool
    {
        return $user->can('messages.update-own')
            && $message->sender_id === $user->id
            && ! $message->isHidden()
            && ! $message->isSystem()
            && $message->created_at->gte(now()->subMinutes(15))
            && $this->create($user, $message->channel);
    }

    public function hide(User $user, Message $message): bool
    {
        return $user->can('messages.hide-own')
            && $message->sender_id === $user->id
            && ! $message->isHidden()
            && ! $message->isSystem()
            && $message->created_at->gte(now()->subMinutes(15))
            && $this->create($user, $message->channel);
    }

    public function moderate(User $user, Message $message): bool
    {
        if (! $user->can('messages.moderate') || $message->isHidden()) {
            return false;
        }

        if ($this->access->isAdministrator($user)) {
            return $this->access->canAccessChannel($user, $message->channel);
        }

        return $this->access->isCurrentClassTeacher($user, $message->channel->schoolClass)
            && $this->access->canAccessChannel($user, $message->channel);
    }

    public function viewReactions(User $user, Message $message): bool
    {
        return $user->can('reactions.view') && $this->view($user, $message);
    }

    public function setReaction(User $user, Message $message): bool
    {
        return $user->can('reactions.create') && ! $message->isHidden() && ! $message->isSystem() && $this->create($user, $message->channel);
    }

    public function updateOwn(User $user, Message $message): bool
    {
        return $user->can('reactions.update-own') && $this->setReaction($user, $message);
    }

    public function deleteOwn(User $user, Message $message): bool
    {
        return $user->can('reactions.delete-own') && $this->setReaction($user, $message);
    }
}
