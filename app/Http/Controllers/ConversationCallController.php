<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\IssueConversationCallToken;
use App\Actions\Conversations\StartConversationCall;
use App\Events\ConversationCallSignal;
use App\Models\Conversation;
use App\Models\ConversationCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ConversationCallController extends Controller
{
    public function active(Request $request): JsonResponse
    {
        $call = ConversationCall::query()
            ->with(['conversation', 'initiator'])
            ->where('status', 'active')
            ->whereHas('participants', fn ($participants) => $participants
                ->where('user_id', $request->user()->id)
                ->whereNotNull('joined_at')
                ->whereNull('left_at')
                ->whereNull('declined_at'))
            ->latest('started_at')
            ->first();

        if ($call) Gate::authorize('issueToken', $call);

        return response()->json(['call' => $call ? $this->payload($call) : null]);
    }

    public function store(Request $request, Conversation $conversation, StartConversationCall $action): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:audio,video']]);
        $call = $action->handle($request->user(), $conversation, $data['type']);

        return response()->json(['call' => $this->payload($call), 'room_url' => route('conversation-calls.room', $call)], 201);
    }

    public function respond(Request $request, ConversationCall $call): JsonResponse
    {
        $this->authorize('respond', $call);
        $data = $request->validate(['decision' => ['required', 'in:accepted,declined']]);
        DB::transaction(function () use ($request, $call, $data) {
            $locked = ConversationCall::query()->lockForUpdate()->findOrFail($call->id);
            $participant = $locked->participants()->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            if ($data['decision'] === 'accepted') {
                $participant->update(['joined_at' => $participant->joined_at ?? now()]);
                $locked->participants()
                    ->where('user_id', $locked->initiated_by_user_id)
                    ->whereNull('joined_at')
                    ->update(['joined_at' => now()]);
                $locked->update(['status' => 'active', 'started_at' => $locked->started_at ?? now()]);
            } else {
                $participant->update(['declined_at' => now()]);
                $locked->update(['status' => 'declined', 'ended_at' => now()]);
            } $locked->load(['conversation', 'initiator']);
            ConversationCallSignal::dispatch($locked, $data['decision']);
        });

        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, ConversationCall $call): JsonResponse
    {
        $this->authorize('cancel', $call);
        $call->update(['status' => 'cancelled', 'ended_at' => now()]);
        $call->load(['conversation', 'initiator']);
        ConversationCallSignal::dispatch($call, 'cancelled');

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request, ConversationCall $call): JsonResponse
    {
        $this->authorize('view', $call);
        DB::transaction(function () use ($request, $call) {
            $call->participants()->where('user_id', $request->user()->id)->whereNull('left_at')->update(['left_at' => now()]);
            $hasRemainingParticipants = $call->participants()->whereNull('left_at')->whereNull('declined_at')->exists();
            $ended = $call->conversation->type === 'direct' || ! $hasRemainingParticipants;
            if ($ended) $call->update(['status' => 'ended', 'ended_at' => now()]);
            $call->load(['conversation', 'initiator']);
            ConversationCallSignal::dispatch($call, $ended ? 'ended' : 'left');
        });

        return response()->json(['ok' => true]);
    }

    public function token(Request $request, ConversationCall $call, IssueConversationCallToken $action): JsonResponse
    {
        $result = $action->handle($request->user(), $call);

        return response()->json(['token' => $result['token']->token, 'server_url' => config('livekit.url'), 'expires_at' => $result['token']->expiresAt->toIso8601String(), 'identity' => $result['identity'], 'started_at' => $call->started_at?->toIso8601String(), 'server_now_at' => now()->toIso8601String()]);
    }

    public function room(Request $request, ConversationCall $call)
    {
        $this->authorize('issueToken', $call);
        $call->load(['conversation', 'initiator']);

        return Inertia::render('Conversations/CallRoom', ['call' => $this->payload($call)]);
    }

    private function payload(ConversationCall $call): array
    {
        return ['uuid' => $call->public_uuid, 'type' => $call->type, 'status' => $call->status, 'started_at' => $call->started_at?->toIso8601String(), 'conversation_uuid' => $call->conversation->public_uuid, 'name' => $call->conversation->type === 'group' ? $call->conversation->name : 'Private call', 'initiator' => ['name' => $call->initiator->name, 'avatar_url' => $call->initiator->avatarUrl()], 'participants' => $call->participants()->with('user')->whereNull('declined_at')->whereNull('left_at')->get()->map(fn ($participant) => ['id' => $participant->user->id, 'name' => $participant->user->name, 'avatar_url' => $participant->user->avatarUrl()])->values()];
    }
}
