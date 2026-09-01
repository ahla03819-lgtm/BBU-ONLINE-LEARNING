<?php

namespace App\Actions\Conversations;

use App\Events\ConversationCallSignal;
use App\Models\Conversation;
use App\Models\ConversationCall;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartConversationCall
{
    public function handle(User $actor, Conversation $conversation, string $type): ConversationCall
    {
        if (! in_array($type, ['audio', 'video'], true) || $actor->cannot('view', $conversation)) {
            throw ValidationException::withMessages(['call' => 'You cannot start this call.']);
        }
        if (ConversationCall::query()->whereIn('status', ['ringing', 'active'])->whereHas('participants', fn ($q) => $q->where('user_id', $actor->id)->whereNull('left_at')->whereNull('declined_at'))->exists()) {
            throw ValidationException::withMessages(['call' => 'You are already in another call.']);
        }

        return DB::transaction(function () use ($actor, $conversation, $type) {
            $members = $conversation->members()->whereNull('left_at')->pluck('user_id');
            if ($conversation->type === 'direct' && $members->count() !== 2) {
                throw ValidationException::withMessages(['call' => 'This direct conversation cannot be called.']);
            }
            $status = $conversation->type === 'direct' ? 'ringing' : 'active';
            $call = ConversationCall::create(['conversation_id' => $conversation->id, 'initiated_by_user_id' => $actor->id, 'type' => $type, 'status' => $status, 'started_at' => $status === 'active' ? now() : null]);
            foreach ($members as $userId) {
                $call->participants()->create(['user_id' => $userId, 'invited_at' => now(), 'joined_at' => $userId === $actor->id && $status === 'active' ? now() : null]);
            }
            $call->load(['conversation', 'initiator']);
            ConversationCallSignal::dispatch($call, 'started');

            return $call;
        });
    }
}
