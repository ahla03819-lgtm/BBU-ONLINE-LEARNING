<?php

namespace App\Actions\Conversations;

use App\Models\ConversationCall;
use App\Models\User;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Illuminate\Support\Facades\Gate;

class IssueConversationCallToken
{
    public function __construct(private readonly LiveKitTokenIssuer $issuer) {}

    public function handle(User $actor, ConversationCall $call): array
    {
        Gate::forUser($actor)->authorize('issueToken', $call);
        $identity = 'conversation-call:'.$call->public_uuid.':'.$actor->id;
        $issued = $this->issuer->issue($call->livekit_room_name, $identity, $actor->name, ['camera', 'microphone', 'screen_share', 'screen_share_audio'], json_encode(['avatar_url' => $actor->avatarUrl()], JSON_THROW_ON_ERROR));

        return ['token' => $issued, 'identity' => $identity];
    }
}
