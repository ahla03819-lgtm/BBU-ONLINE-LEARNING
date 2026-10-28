<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\IssueGuestMeetingToken;
use App\Models\GuestMeetingSession;
use Illuminate\Http\JsonResponse;

class GuestMeetingTokenController extends Controller
{
    public function store(IssueGuestMeetingToken $action): JsonResponse
    {
        /** @var GuestMeetingSession $session */
        $session = request()->attributes->get('guestMeetingSession');
        $result = $action->handle($session);

        return response()->json([
            'token' => $result['token']->token,
            'server_url' => config('livekit.url'),
            'expires_at' => $result['token']->expiresAt->toIso8601String(),
            'lifecycle_version' => $result['lifecycle_version'],
            'participant' => [
                'display_name' => $result['participant']->display_name_snapshot,
                'role' => $result['participant']->role->value,
            ],
        ]);
    }
}
