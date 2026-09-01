<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->activeMember($user, $conversation);
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $this->activeMember($user, $conversation);
    }

    public function manage(User $user, Conversation $conversation): bool
    {
        return $conversation->type === 'group' && $conversation->members()->where('user_id', $user->id)->whereNull('left_at')->where('role', 'manager')->exists();
    }

    private function activeMember(User $user, Conversation $conversation): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail() && $conversation->members()->where('user_id', $user->id)->whereNull('left_at')->exists();
    }
}
