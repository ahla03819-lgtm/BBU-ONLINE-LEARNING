<?php

namespace App\Http\Controllers;

use App\Actions\Messaging\RemoveMessageReaction;
use App\Actions\Messaging\SetMessageReaction;
use App\Enums\ReactionType;
use App\Http\Requests\Messaging\RemoveMessageReactionRequest;
use App\Http\Requests\Messaging\SetMessageReactionRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;

class MessageReactionController extends Controller
{
    public function update(SetMessageReactionRequest $request, SchoolClass $schoolClass, Channel $channel, Message $message, SetMessageReaction $action): JsonResponse
    {
        $this->ensureScope($schoolClass, $channel, $message);

        return response()->json(['reactions' => $action->handle($message, $request->user(), ReactionType::from($request->validated('reaction')))]);
    }

    public function destroy(RemoveMessageReactionRequest $request, SchoolClass $schoolClass, Channel $channel, Message $message, RemoveMessageReaction $action): JsonResponse
    {
        $this->ensureScope($schoolClass, $channel, $message);

        return response()->json(['reactions' => $action->handle($message, $request->user())]);
    }

    private function ensureScope(SchoolClass $class, Channel $channel, Message $message): void
    {
        abort_unless($channel->school_class_id === $class->id && $message->channel_id === $channel->id, 404);
    }
}
