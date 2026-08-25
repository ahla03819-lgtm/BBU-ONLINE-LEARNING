<?php

namespace App\Http\Controllers;

use App\Actions\Messaging\MarkChannelRead;
use App\Http\Requests\Messaging\MarkChannelReadRequest;
use App\Models\Channel;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;

class ChannelReadStateController extends Controller
{
    public function update(MarkChannelReadRequest $request, SchoolClass $schoolClass, Channel $channel, MarkChannelRead $action): JsonResponse
    {
        abort_unless($channel->school_class_id === $schoolClass->id, 404);
        $message = $channel->messages()->find($request->validated('message_id'));
        abort_unless($message, 422, 'The read cursor must belong to this channel.');
        $state = $action->handle($channel, $request->user(), $message);

        return response()->json(['last_read_message_id' => $state->last_read_message_id, 'last_read_at' => $state->last_read_at?->toISOString()]);
    }
}
