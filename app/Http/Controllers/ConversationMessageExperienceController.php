<?php

namespace App\Http\Controllers;

use App\Events\ConversationMessageSent;
use App\Events\ConversationMutationChanged;
use App\Events\ConversationTyping;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageReaction;
use App\Models\ConversationMessageRead;
use App\Models\ConversationPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ConversationMessageExperienceController extends Controller
{
    private const EMOJI = ['👍', '❤️', '😂', '😮', '😢', '👏'];

    public function update(Request $request, Conversation $conversation, ConversationMessage $message): JsonResponse
    {
        $this->message($request, $conversation, $message);
        abort_unless($message->sender_user_id === $request->user()->id && $message->deleted_at === null, 403);
        $body = trim((string) $request->validate(['body' => ['required', 'string', 'max:4000']])['body']);
        abort_if($body === '', 422, 'A message cannot be empty.');
        $message->update(['body' => $body]);
        ConversationMutationChanged::dispatch($conversation, 'message.edited', ['message_id' => $message->id, 'body' => $message->body, 'edited_at' => $message->updated_at?->toIso8601String()]);

        return response()->json(['message' => $message->fresh()]);
    }

    public function destroy(Request $request, Conversation $conversation, ConversationMessage $message): JsonResponse
    {
        $this->message($request, $conversation, $message);
        abort_unless($message->sender_user_id === $request->user()->id, 403);
        $message->update(['body' => null, 'deleted_at' => now()]);
        ConversationMutationChanged::dispatch($conversation, 'message.deleted', ['message_id' => $message->id, 'deleted' => true]);

        return response()->json(['message' => $message->fresh()]);
    }

    public function react(Request $request, Conversation $conversation, ConversationMessage $message): JsonResponse
    {
        $this->message($request, $conversation, $message);
        $emoji = $request->validate(['emoji' => ['required', 'string', 'in:'.implode(',', self::EMOJI)]])['emoji'];
        $reaction = ConversationMessageReaction::query()->where(['conversation_message_id' => $message->id, 'user_id' => $request->user()->id, 'emoji' => $emoji])->first();
        $reaction ? $reaction->delete() : ConversationMessageReaction::create(['conversation_message_id' => $message->id, 'user_id' => $request->user()->id, 'emoji' => $emoji]);
        $summary = $message->reactions()->selectRaw('emoji, count(*) as count')->groupBy('emoji')->pluck('count', 'emoji');
        ConversationMutationChanged::dispatch($conversation, 'message.reactions', ['message_id' => $message->id, 'reactions' => $summary]);

        return response()->json(['reactions' => $summary]);
    }

    public function pin(Request $request, Conversation $conversation, ConversationMessage $message): JsonResponse
    {
        $this->message($request, $conversation, $message);
        if ($conversation->type === 'group') {
            abort_unless($conversation->members()->where(['user_id' => $request->user()->id, 'role' => 'manager'])->whereNull('left_at')->exists(), 403);
        }
        ConversationPin::updateOrCreate(['conversation_id' => $conversation->id], ['conversation_message_id' => $message->id, 'pinned_by_user_id' => $request->user()->id]);
        ConversationMutationChanged::dispatch($conversation, 'pin.changed', ['pinned_message_id' => $message->id]);

        return response()->json(['pinned_message_id' => $message->id]);
    }

    public function unpin(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        if ($conversation->type === 'group') {
            abort_unless($conversation->members()->where(['user_id' => $request->user()->id, 'role' => 'manager'])->whereNull('left_at')->exists(), 403);
        }
        ConversationPin::query()->where('conversation_id', $conversation->id)->delete();
        ConversationMutationChanged::dispatch($conversation, 'pin.changed', ['pinned_message_id' => null]);

        return response()->json(['pinned_message_id' => null]);
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $ids = collect($request->validate(['message_ids' => ['required', 'array', 'max:50'], 'message_ids.*' => ['integer', 'distinct']])['message_ids']);
        $valid = $conversation->messages()->whereIn('id', $ids)->where('sender_user_id', '!=', $request->user()->id)->pluck('id');
        abort_unless($valid->count() === $ids->count(), 404);
        foreach ($valid as $id) {
            ConversationMessageRead::updateOrCreate(['conversation_message_id' => $id, 'user_id' => $request->user()->id], ['read_at' => now()]);
        }
        ConversationMutationChanged::dispatch($conversation, 'messages.read', ['message_ids' => $valid->values(), 'reader_id' => $request->user()->id]);

        return response()->json(['read_message_ids' => $valid->values()]);
    }

    public function search(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'max:120']])['q']);
        abort_if($term === '', 422, 'Search text is required.');
        $messages = $conversation->messages()->whereNull('deleted_at')->where('body', 'like', '%'.$term.'%')->latest('id')->limit(25)->with('sender:id,name')->get();

        return response()->json(['results' => $messages->map(fn (ConversationMessage $message) => ['id' => $message->id, 'body' => $message->body, 'sender_name' => $message->sender->name, 'created_at' => $message->created_at?->toIso8601String()])]);
    }

    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $typing = (bool) $request->validate(['typing' => ['required', 'boolean']])['typing'];
        ConversationTyping::dispatch($conversation, $request->user(), $typing);

        return response()->json(['ok' => true]);
    }

    public function forward(Request $request, Conversation $conversation, ConversationMessage $message): JsonResponse
    {
        $this->message($request, $conversation, $message);
        abort_if($message->deleted_at !== null, 422, 'Deleted messages cannot be forwarded.');
        $destination = Conversation::query()->where('public_uuid', $request->validate(['destination_conversation' => ['required', 'uuid']])['destination_conversation'])->firstOrFail();
        $this->authorize('view', $destination);
        $copied = [];
        try {
            $forwarded = DB::transaction(function () use ($request, $message, $destination, &$copied) {
                $forwarded = $destination->messages()->create(['sender_user_id' => $request->user()->id, 'body' => $message->body]);
                foreach ($message->attachments as $attachment) {
                    abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 422, 'A forwarded attachment is unavailable.');
                    $path = 'conversation-attachments/'.$destination->public_uuid.'/'.$forwarded->id.'/'.Str::uuid().'.'.$attachment->extension;
                    Storage::disk($attachment->disk)->copy($attachment->path, $path);
                    $copied[] = [$attachment->disk, $path];
                    $forwarded->attachments()->create($attachment->only(['disk', 'original_name', 'extension', 'mime_type', 'size_bytes', 'attachment_type', 'duration_seconds']) + ['path' => $path]);
                }

                return $forwarded;
            });
        } catch (\Throwable $exception) {
            foreach ($copied as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }
        $destination->touch();
        $forwarded->load('conversation', 'sender:id,name,avatar_path', 'attachments');
        ConversationMessageSent::dispatch($forwarded);

        return response()->json(['message_id' => $forwarded->id], 201);
    }

    private function message(Request $request, Conversation $conversation, ConversationMessage $message): void
    {
        $this->authorize('view', $conversation);
        abort_unless($message->conversation_id === $conversation->id, 404);
    }
}
