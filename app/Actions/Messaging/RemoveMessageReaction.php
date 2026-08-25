<?php

namespace App\Actions\Messaging;

use App\Events\MessageReactionsChanged;
use App\Models\Message;
use App\Models\User;
use App\Support\ReactionPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RemoveMessageReaction
{
    public function handle(Message $message, User $user): array
    {
        return DB::transaction(function () use ($message, $user) {
            $locked = Message::query()->lockForUpdate()->findOrFail($message->id);
            Gate::forUser($user)->authorize('deleteOwn', $locked);
            $deleted = $locked->reactions()->where('user_id', $user->id)->delete();
            if ($deleted) {
                $locked->increment('reactions_version');
                $locked->refresh();
                MessageReactionsChanged::dispatch($locked);
            }

            return ReactionPayload::make($locked, $user);
        });
    }
}
