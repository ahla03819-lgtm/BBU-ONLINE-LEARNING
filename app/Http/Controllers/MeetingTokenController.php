<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\IssueMeetingToken;
use App\Http\Requests\Meetings\IssueMeetingTokenRequest;
use App\Models\Meeting;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;

class MeetingTokenController extends Controller
{
    public function store(IssueMeetingTokenRequest $request, SchoolClass $schoolClass, Meeting $meeting, IssueMeetingToken $action): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        $result = $action->handle($request->user(), $meeting);

        return response()->json([
            'token' => $result['token']->token,
            'server_url' => config('livekit.url'),
            'expires_at' => $result['token']->expiresAt->toIso8601String(),
            'lifecycle_version' => $result['lifecycle_version'],
            'participant' => [
                'id' => $result['participant']->id,
                'display_name' => $result['participant']->display_name_snapshot,
                'role' => $result['participant']->role->value,
            ],
        ]);
    }
}
