<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\OpenDirectConversation;
use App\Events\ConversationMembershipChanged;
use App\Events\ConversationMessageSent;
use App\Http\Requests\Conversations\StoreConversationMessageRequest;
use App\Http\Requests\Conversations\StoreGroupConversationRequest;
use App\Http\Requests\Conversations\UpdateGroupConversationRequest;
use App\Models\Conversation;
use App\Models\ConversationCall;
use App\Models\ConversationMember;
use App\Models\ConversationMessageAttachment;
use App\Models\ConversationPin;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ConversationAccess;
use App\Services\Conversations\ConversationAttachmentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    public function index(Request $request, ConversationAccess $access): Response
    {
        $conversations = $access->conversationsFor($request->user())->with(['members.user:id,name,avatar_path', 'messages' => fn ($messages) => $messages->latest('id')->limit(1)->with('sender:id,name,avatar_path')])->latest('updated_at')->get();

        return Inertia::render('Conversations/Index', ['conversations' => $conversations->map(fn (Conversation $conversation) => $this->summary($conversation, $request->user()))->values(), 'eligibleUsers' => $access->eligibleUsers($request->user())->orderBy('name')->get(['id', 'name', 'avatar_path'])->map(fn (User $user) => $this->user($user))->values()]);
    }

    public function show(Request $request, Conversation $conversation, ConversationAccess $access): Response
    {
        $this->authorize('view', $conversation);
        $hasMessageExperience = Schema::hasTable('conversation_message_reactions') && Schema::hasTable('conversation_pins');
        $conversations = $access->conversationsFor($request->user())->with(['members.user:id,name,avatar_path', 'messages' => fn ($messages) => $messages->latest('id')->limit(1)->with('sender:id,name,avatar_path')])->latest('updated_at')->get();
        $conversation->load([
            'members.user:id,name,avatar_path',
            'messages' => function ($messages) use ($hasMessageExperience) {
                $messages->with(['sender:id,name,avatar_path', 'attachments', 'replyTo.sender:id,name']);
                if ($hasMessageExperience) {
                    $messages->with('reactions');
                }
                $messages->latest('id')->limit(50);
            },
            'calls' => fn ($calls) => $calls->with('initiator:id,name,avatar_path')->latest('id')->limit(20),
        ]);

        return Inertia::render('Conversations/Index', ['conversations' => $conversations->map(fn (Conversation $item) => $this->summary($item, $request->user()))->values(), 'selectedConversation' => $this->detail($conversation, $request->user(), $hasMessageExperience), 'eligibleUsers' => $access->eligibleUsers($request->user())->orderBy('name')->get(['id', 'name', 'avatar_path'])->map(fn (User $user) => $this->user($user))->values()]);
    }

    public function openDirect(Request $request, SchoolClass $schoolClass, User $user, OpenDirectConversation $action, ConversationAccess $access): RedirectResponse
    {
        abort_unless($access->isCurrentClassMember($request->user(), $schoolClass) && $access->isCurrentClassMember($user, $schoolClass), 403);
        $conversation = $action->handle($request->user(), $user);

        return redirect()->route('conversations.show', $conversation);
    }

    public function storeGroup(StoreGroupConversationRequest $request, ConversationAccess $access): RedirectResponse
    {
        $actor = $request->user();
        $ids = collect($request->validated('member_ids'))->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === $actor->id)->values();
        $eligible = $access->eligibleUsers($actor)->whereIn('id', $ids)->pluck('id');
        if ($eligible->count() !== $ids->count()) {
            throw ValidationException::withMessages(['member_ids' => 'Every group member must be available through your current class workspace.']);
        }
        $conversation = DB::transaction(function () use ($actor, $ids, $request) {
            $conversation = Conversation::create(['type' => 'group', 'name' => trim($request->validated('name')), 'created_by_user_id' => $actor->id]);
            $conversation->members()->create(['user_id' => $actor->id, 'role' => 'manager', 'joined_at' => now()]);
            $conversation->members()->createMany($ids->map(fn ($id) => ['user_id' => $id, 'role' => 'member', 'joined_at' => now()])->all());

            return $conversation;
        });
        $conversation->members()->where('user_id', '!=', $actor->id)->get()->each(
            fn (ConversationMember $member) => ConversationMembershipChanged::dispatch($conversation, $member, $actor, 'added'),
        );

        return redirect()->route('conversations.show', $conversation);
    }

    public function send(StoreConversationMessageRequest $request, Conversation $conversation, ConversationAttachmentStorage $storage): JsonResponse
    {
        $this->authorize('send', $conversation);
        $files = $request->file('attachments', []);
        $replyTo = $request->validated('reply_to_message_id')
            ? $conversation->messages()->findOrFail($request->validated('reply_to_message_id'))
            : null;
        $stored = [];
        foreach ($files as $position => $file) {
            $this->validateAttachment($file);
            $stored[] = $storage->store($file, $conversation->public_uuid, (string) Str::uuid()) + ['duration_seconds' => $request->validated('duration_seconds.'.$position)];
        }
        $message = DB::transaction(function () use ($conversation, $request, $stored, $replyTo) {
            $message = $conversation->messages()->create(['sender_user_id' => $request->user()->id, 'reply_to_message_id' => $replyTo?->id, 'body' => $request->validated('body')]);
            foreach ($stored as $item) {
                $message->attachments()->create($item);
            }

            return $message;
        });
        $conversation->touch();
        $message->load('conversation', 'sender:id,name,avatar_path', 'attachments', 'replyTo.sender:id,name');
        ConversationMessageSent::dispatch($message);

        return response()->json(['message' => $this->message($message)], 201);
    }

    public function rename(UpdateGroupConversationRequest $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('manage', $conversation);
        $conversation->update(['name' => trim($request->validated('name'))]);

        return back();
    }

    public function addMembers(Request $request, Conversation $conversation, ConversationAccess $access): RedirectResponse
    {
        $this->authorize('manage', $conversation);
        $data = $request->validate(['member_ids' => ['required', 'array', 'min:1', 'max:50'], 'member_ids.*' => ['integer', 'distinct']]);
        $ids = collect($data['member_ids'])->map(fn ($id) => (int) $id)->values();
        $eligible = $access->eligibleUsers($request->user())->whereIn('id', $ids)->pluck('id');
        if ($eligible->count() !== $ids->count()) {
            throw ValidationException::withMessages(['member_ids' => 'Every group member must be available through your current class workspace.']);
        }
        $added = DB::transaction(function () use ($conversation, $ids) {
            $members = collect();
            foreach ($ids as $id) {
                $existing = ConversationMember::query()->where('conversation_id', $conversation->id)->where('user_id', $id)->lockForUpdate()->first();
                $member = ConversationMember::updateOrCreate(['conversation_id' => $conversation->id, 'user_id' => $id], ['role' => 'member', 'joined_at' => now(), 'left_at' => null]);
                if (! $existing || $existing->left_at !== null) {
                    $members->push($member);
                }
            }

            return $members;
        });
        $added->each(fn (ConversationMember $member) => ConversationMembershipChanged::dispatch($conversation, $member, $request->user(), 'added'));

        return back();
    }

    public function removeMember(Request $request, Conversation $conversation, User $user): RedirectResponse
    {
        $this->authorize('manage', $conversation);
        abort_if($user->id === $request->user()->id, 422, 'Managers must use leave group.');
        $member = $conversation->members()->where('user_id', $user->id)->whereNull('left_at')->firstOrFail();
        $member->update(['left_at' => now()]);
        ConversationMembershipChanged::dispatch($conversation, $member, $request->user(), 'removed');

        return back();
    }

    public function leave(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('view', $conversation);
        $member = $conversation->members()->where('user_id', $request->user()->id)->whereNull('left_at')->firstOrFail();
        $promoted = DB::transaction(function () use ($conversation, $member) {
            $successor = null;
            if ($conversation->type === 'group' && $member->role === 'manager') {
                $successor = $conversation->members()->whereNull('left_at')->whereKeyNot($member->id)->orderBy('joined_at')->orderBy('id')->lockForUpdate()->first();
                if ($successor) {
                    $successor->update(['role' => 'manager']);
                }
            }
            $member->update(['left_at' => now()]);

            return $successor;
        });
        if ($promoted) {
            ConversationMembershipChanged::dispatch($conversation, $promoted, $request->user(), 'promoted');
        }

        return redirect()->route('conversations.index');
    }

    private function summary(Conversation $conversation, User $viewer): array
    {
        $latest = $conversation->messages->first();

        return [...$this->base($conversation, $viewer), 'latest_message' => $latest ? $this->message($latest) : null];
    }

    private function detail(Conversation $conversation, User $viewer, bool $hasMessageExperience = false): array
    {
        return [
            ...$this->base($conversation, $viewer),
            'messages' => $conversation->messages->reverse()->values()->map(fn ($message) => $this->message($message))->values(),
            'members' => $conversation->members->whereNull('left_at')->map(fn ($member) => [...$this->user($member->user), 'role' => $member->role])->values(),
            'calls' => $conversation->calls->map(fn (ConversationCall $call) => $this->call($call, $viewer))->values(),
            'can_manage' => $viewer->can('manage', $conversation),
            'pinned_message_id' => $hasMessageExperience ? ConversationPin::query()->where('conversation_id', $conversation->id)->value('conversation_message_id') : null,
        ];
    }

    private function base(Conversation $conversation, User $viewer): array
    {
        $other = $conversation->type === 'direct' ? $conversation->members->first(fn ($member) => $member->user_id !== $viewer->id)?->user : null;

        return ['uuid' => $conversation->public_uuid, 'type' => $conversation->type, 'name' => $conversation->type === 'direct' ? $other?->name : $conversation->name, 'avatar_url' => $other?->avatarUrl(), 'url' => route('conversations.show', $conversation)];
    }

    private function user(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'avatar_url' => $user->avatarUrl()];
    }

    private function message($message): array
    {
        $reply = $message->replyTo;

        return ['id' => $message->id, 'body' => $message->deleted_at ? null : $message->body, 'deleted' => $message->deleted_at !== null, 'edited' => $message->updated_at?->gt($message->created_at), 'created_at' => $message->created_at?->toIso8601String(), 'sender' => $this->user($message->sender), 'reply' => $reply ? ['id' => $reply->id, 'sender_name' => $reply->sender?->name, 'body' => $reply->deleted_at ? 'Message unavailable' : $reply->body, 'unavailable' => $reply->deleted_at !== null] : null, 'reactions' => $message->relationLoaded('reactions') ? $message->reactions->countBy('emoji')->all() : [], 'attachments' => $message->deleted_at ? [] : $message->attachments->map(fn (ConversationMessageAttachment $attachment) => ['uuid' => $attachment->public_uuid, 'name' => $attachment->original_name, 'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes, 'type' => $attachment->attachment_type, 'duration_seconds' => $attachment->duration_seconds, 'url' => route('conversations.attachments.show', [$message->conversation, $attachment])])->values()];
    }

    private function validateAttachment(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        // Browser MediaRecorder implementations may retain MIME parameters such
        // as ";codecs=opus". Validate the underlying server-detected media type.
        $mime = strtolower(trim(explode(';', (string) $file->getMimeType(), 2)[0]));
        $allowed = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'mp3' => ['audio/mpeg'], 'm4a' => ['audio/mp4', 'audio/x-m4a'], 'ogg' => ['audio/ogg'], 'webm' => ['audio/webm', 'video/webm'], 'mp4' => ['video/mp4'], 'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'], 'doc' => ['application/msword'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'xls' => ['application/vnd.ms-excel'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'ppt' => ['application/vnd.ms-powerpoint'], 'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation']];
        abort_unless($file->isValid() && isset($allowed[$extension]) && in_array($mime, $allowed[$extension], true), 422, 'Unsupported or invalid attachment.');
        $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'];
        $limit = in_array($extension, ['mp4', 'webm'], true) && str_starts_with($mime, 'video/') ? 100 * 1024 * 1024 : (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? 10 * 1024 * 1024 : (in_array($extension, $documentExtensions, true) ? 500 * 1024 * 1024 : 25 * 1024 * 1024));
        abort_if($file->getSize() > $limit, 422, 'Attachment exceeds the permitted size.');
    }

    private function call(ConversationCall $call, User $viewer): array
    {
        $duration = $call->started_at && $call->ended_at ? $call->started_at->diffInSeconds($call->ended_at) : null;

        return [
            'uuid' => $call->public_uuid,
            'type' => $call->type,
            'status' => $call->status,
            'started_at' => $call->started_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => $duration,
            'missed' => $call->conversation->type === 'direct'
                && in_array($call->status, ['cancelled', 'declined'], true)
                && $call->initiated_by_user_id !== $viewer->id,
            'initiator' => $this->user($call->initiator),
        ];
    }
}
