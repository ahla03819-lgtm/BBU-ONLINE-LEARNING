<?php

namespace App\Http\Controllers;

use App\Actions\Messaging\HideMessage;
use App\Actions\Messaging\ModerateMessage;
use App\Actions\Messaging\SendMessage;
use App\Actions\Messaging\UpdateMessage;
use App\Http\Requests\Messaging\HideMessageRequest;
use App\Http\Requests\Messaging\ModerateMessageRequest;
use App\Http\Requests\Messaging\StoreMessageRequest;
use App\Http\Requests\Messaging\UpdateMessageRequest;
use App\Models\Channel;
use App\Models\ChannelReadState;
use App\Models\Message;
use App\Models\SchoolClass;
use App\Support\MessagePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Request $request, SchoolClass $schoolClass, Channel $channel): JsonResponse
    {
        $this->ensureChannel($schoolClass, $channel);
        $this->authorize('viewAny', [Message::class, $channel]);
        $validated = $request->validate(['before_id' => ['nullable', 'integer', 'min:1', 'prohibits:after_id'], 'after_id' => ['nullable', 'integer', 'min:0', 'prohibits:before_id']]);
        $query = $channel->messages()->with(['sender:id,name', 'replyTo.sender:id,name']);
        $ascending = isset($validated['after_id']);
        if (isset($validated['before_id'])) {
            $query->where('id', '<', $validated['before_id']);
        }
        if ($ascending) {
            $query->where('id', '>', $validated['after_id']);
        }
        $messages = $query->orderBy('id', $ascending ? 'asc' : 'desc')->limit(51)->get();
        $hasMore = $messages->count() > 50;
        $messages = $messages->take(50);
        if (! $ascending) {
            $messages = $messages->reverse()->values();
        }
        $state = ChannelReadState::query()->where('channel_id', $channel->id)->where('user_id', $request->user()->id)->first();
        $latestVisibleId = $channel->messages()->max('id');
        $unreadCount = $latestVisibleId ? $channel->messages()->where('id', '>', $state?->last_read_message_id ?? 0)->count() : 0;

        return response()->json(['messages' => $messages->map(fn (Message $message) => MessagePayload::make($message))->values(), 'has_more' => $hasMore, 'read_state' => ['last_read_message_id' => $state?->last_read_message_id, 'unread_count' => $unreadCount]]);
    }

    public function store(StoreMessageRequest $request, SchoolClass $schoolClass, Channel $channel, SendMessage $action): JsonResponse
    {
        $this->ensureChannel($schoolClass, $channel);
        $reply = $request->validated('reply_to_id') ? $channel->messages()->find($request->validated('reply_to_id')) : null;
        abort_if($request->validated('reply_to_id') && ! $reply, 422, 'The reply target must belong to this channel.');
        $message = $action->handle($channel, $request->user(), $request->validated('client_uuid'), $request->validated('body'), $reply);

        return response()->json(['message' => MessagePayload::make($message)], $message->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateMessageRequest $request, SchoolClass $schoolClass, Channel $channel, Message $message, UpdateMessage $action): JsonResponse
    {
        $this->ensureMessage($schoolClass, $channel, $message);

        return response()->json(['message' => MessagePayload::make($action->handle($message, $request->validated('body')))]);
    }

    public function hide(HideMessageRequest $request, SchoolClass $schoolClass, Channel $channel, Message $message, HideMessage $action): JsonResponse
    {
        $this->ensureMessage($schoolClass, $channel, $message);

        return response()->json(['message' => MessagePayload::make($action->handle($message, $request->user()))]);
    }

    public function moderate(ModerateMessageRequest $request, SchoolClass $schoolClass, Channel $channel, Message $message, ModerateMessage $action): JsonResponse
    {
        $this->ensureMessage($schoolClass, $channel, $message);

        return response()->json(['message' => MessagePayload::make($action->handle($message, $request->user(), $request->validated('reason')))]);
    }

    private function ensureChannel(SchoolClass $class, Channel $channel): void
    {
        abort_unless($channel->school_class_id === $class->id, 404);
    }

    private function ensureMessage(SchoolClass $class, Channel $channel, Message $message): void
    {
        $this->ensureChannel($class, $channel);
        abort_unless($message->channel_id === $channel->id, 404);
    }
}
