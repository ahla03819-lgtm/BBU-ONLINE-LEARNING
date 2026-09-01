<?php

namespace App\Policies;

use App\Models\ConversationCall;
use App\Models\User;

class ConversationCallPolicy
{
    private function activeMember(User $user, ConversationCall $call): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail() && $call->conversation->members()->where('user_id', $user->id)->whereNull('left_at')->exists();
    }

    public function view(User $user, ConversationCall $call): bool
    {
        return $this->activeMember($user, $call);
    }

    public function respond(User $user, ConversationCall $call): bool
    {
        return $this->activeMember($user, $call)
            && $call->status === 'ringing'
            && $call->initiated_by_user_id !== $user->id
            && $call->participants()->where('user_id', $user->id)->whereNull('declined_at')->exists()
            && ! ConversationCall::query()
                ->whereKeyNot($call->id)
                ->where('status', 'active')
                ->whereHas('participants', fn ($participants) => $participants
                    ->where('user_id', $user->id)
                    ->whereNull('left_at')
                    ->whereNull('declined_at'))
                ->exists();
    }

    public function cancel(User $user, ConversationCall $call): bool
    {
        return $call->initiated_by_user_id === $user->id && $call->status === 'ringing' && $this->activeMember($user, $call);
    }

    public function issueToken(User $user, ConversationCall $call): bool
    {
        return $this->activeMember($user, $call) && $call->status === 'active' && $call->participants()->where('user_id', $user->id)->whereNull('declined_at')->whereNull('left_at')->exists();
    }
}
