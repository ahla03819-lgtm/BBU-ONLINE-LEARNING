<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\DecideMeetingJoinRequest;
use App\Enums\MeetingJoinRequestStatus;
use App\Http\Requests\Meetings\DecideMeetingJoinRequestRequest;
use App\Models\Meeting;
use App\Models\MeetingJoinRequest;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;

class MeetingJoinRequestController extends Controller
{
    public function index(SchoolClass $schoolClass, Meeting $meeting): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        $this->authorize('manageJoinRequests', $meeting);

        return response()->json(['requests' => $meeting->joinRequests()->with('requester:id,name')->where('status', MeetingJoinRequestStatus::Pending)->orderBy('requested_at')->get()->map(fn (MeetingJoinRequest $request) => ['reference' => $request->public_uuid, 'display_name' => $request->requester->name, 'requested_at' => $request->requested_at?->toIso8601String()])]);
    }

    public function update(DecideMeetingJoinRequestRequest $request, SchoolClass $schoolClass, Meeting $meeting, MeetingJoinRequest $joinRequest, DecideMeetingJoinRequest $action): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        abort_unless($joinRequest->meeting_id === $meeting->id, 404);
        $decided = $action->handle($request->user(), $joinRequest, MeetingJoinRequestStatus::from($request->validated('decision')));

        return response()->json(['request' => MeetingWaitingRoomController::requestPayload($decided)]);
    }

    private function ensureScope(SchoolClass $schoolClass, Meeting $meeting): void
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
    }
}
