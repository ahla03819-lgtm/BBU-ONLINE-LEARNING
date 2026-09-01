<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenDirectConversation
{
    public function __construct(private readonly ConversationAccess $access) {}

    public function handle(User $actor, User $recipient): Conversation
    {
        if (! $this->access->hasSharedCurrentClass($actor, $recipient)) {
            throw ValidationException::withMessages(['recipient' => 'This user is not available for a private chat.']);
        }
        $pair = collect([$actor->id, $recipient->id])->sort()->implode(':');
        try {
            return DB::transaction(function () use ($actor, $recipient, $pair) {
                $conversation = Conversation::query()->where('direct_pair_key', $pair)->lockForUpdate()->first();
                if (! $conversation) {
                    $conversation = Conversation::create(['type' => 'direct', 'direct_pair_key' => $pair, 'created_by_user_id' => $actor->id]);
                    $conversation->members()->createMany([['user_id' => $actor->id, 'role' => 'member', 'joined_at' => now()], ['user_id' => $recipient->id, 'role' => 'member', 'joined_at' => now()]]);
                }

                return $conversation;
            });
        } catch (QueryException) {
            return Conversation::query()->where('direct_pair_key', $pair)->firstOrFail();
        }
    }
}
