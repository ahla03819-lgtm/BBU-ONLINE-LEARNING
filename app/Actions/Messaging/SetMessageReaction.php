<?php

namespace App\Actions\Messaging;

use App\Enums\ReactionType;
use App\Events\MessageReactionsChanged;
use App\Models\Message;
use App\Models\User;
use App\Support\ReactionPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SetMessageReaction
{
    public function handle(Message $message, User $user, ReactionType $reaction): array
    {
        return DB::transaction(function () use ($message, $user, $reaction) {
            $locked = Message::query()->lockForUpdate()->findOrFail($message->id);
            Gate::forUser($user)->authorize($locked->reactions()->where('user_id', $user->id)->exists() ? 'updateOwn' : 'setReaction', $locked);
            $current = $locked->reactions()->where('user_id', $user->id)->first();
            if ($current?->reaction === $reaction) {
                return ReactionPayload::make($locked, $user);
            }
            $locked->reactions()->updateOrCreate(['user_id' => $user->id], ['reaction' => $reaction]);
            $locked->increment('reactions_version');
            $locked->refresh();
            MessageReactionsChanged::dispatch($locked);

            return ReactionPayload::make($locked, $user);
        });
    }
}
