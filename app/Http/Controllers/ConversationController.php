<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\OpenDirectConversation;
use App\Events\ConversationMessageSent;
use App\Http\Requests\Conversations\StoreConversationMessageRequest;
use App\Http\Requests\Conversations\StoreGroupConversationRequest;
use App\Http\Requests\Conversations\UpdateGroupConversationRequest;
use App\Models\Conversation;
use App\Models\ConversationCall;
use App\Models\ConversationMember;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ConversationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $conversations = $access->conversationsFor($request->user())->with(['members.user:id,name,avatar_path', 'messages' => fn ($messages) => $messages->latest('id')->limit(1)->with('sender:id,name,avatar_path')])->latest('updated_at')->get();
        $conversation->load([
            'members.user:id,name,avatar_path',
            'messages' => fn ($messages) => $messages->with('sender:id,name,avatar_path')->latest('id')->limit(50),
            'calls' => fn ($calls) => $calls->with('initiator:id,name,avatar_path')->latest('id')->limit(20),
        ]);

        return Inertia::render('Conversations/Index', ['conversations' => $conversations->map(fn (Conversation $item) => $this->summary($item, $request->user()))->values(), 'selectedConversation' => $this->detail($conversation, $request->user()), 'eligibleUsers' => $access->eligibleUsers($request->user())->orderBy('name')->get(['id', 'name', 'avatar_path'])->map(fn (User $user) => $this->user($user))->values()]);
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

        return redirect()->route('conversations.show', $conversation);
    }

    public function send(StoreConversationMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('send', $conversation);
        $message = $conversation->messages()->create(['sender_user_id' => $request->user()->id, 'body' => trim($request->validated('body'))]);
        $conversation->touch();
        $message->load('conversation', 'sender:id,name,avatar_path');
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
        foreach ($ids as $id) {
            ConversationMember::updateOrCreate(['conversation_id' => $conversation->id, 'user_id' => $id], ['role' => 'member', 'joined_at' => now(), 'left_at' => null]);
        }

        return back();
    }

    public function removeMember(Request $request, Conversation $conversation, User $user): RedirectResponse
    {
        $this->authorize('manage', $conversation);
        abort_if($user->id === $request->user()->id, 422, 'Managers must use leave group.');
        $member = $conversation->members()->where('user_id', $user->id)->whereNull('left_at')->firstOrFail();
        $member->update(['left_at' => now()]);

        return back();
    }

    public function leave(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('view', $conversation);
        $member = $conversation->members()->where('user_id', $request->user()->id)->whereNull('left_at')->firstOrFail();
        DB::transaction(function () use ($conversation, $member) {
            if ($conversation->type === 'group' && $member->role === 'manager') {
                $successor = $conversation->members()->whereNull('left_at')->whereKeyNot($member->id)->orderBy('joined_at')->orderBy('id')->lockForUpdate()->first();
                if ($successor) {
                    $successor->update(['role' => 'manager']);
                }
            }
            $member->update(['left_at' => now()]);
        });

        return redirect()->route('conversations.index');
    }

    private function summary(Conversation $conversation, User $viewer): array
    {
        $latest = $conversation->messages->first();

        return [...$this->base($conversation, $viewer), 'latest_message' => $latest ? $this->message($latest) : null];
    }

    private function detail(Conversation $conversation, User $viewer): array
    {
        return [
            ...$this->base($conversation, $viewer),
            'messages' => $conversation->messages->reverse()->values()->map(fn ($message) => $this->message($message))->values(),
            'members' => $conversation->members->whereNull('left_at')->map(fn ($member) => [...$this->user($member->user), 'role' => $member->role])->values(),
            'calls' => $conversation->calls->map(fn (ConversationCall $call) => $this->call($call, $viewer))->values(),
            'can_manage' => $viewer->can('manage', $conversation),
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
        return ['id' => $message->id, 'body' => $message->body, 'created_at' => $message->created_at?->toIso8601String(), 'sender' => $this->user($message->sender)];
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
