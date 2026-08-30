<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\CancelMeetingJoinRequest;
use App\Actions\Meetings\RequestMeetingJoin;
use App\Models\Meeting;
use App\Models\MeetingJoinRequest;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Services\MeetingAccess;
use Illuminate\Http\JsonResponse;

class MeetingWaitingRoomController extends Controller
{
    public function show(SchoolClass $schoolClass, Meeting $meeting): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        $this->authorize('join', $meeting);

        $joinRequest = $meeting->joinRequests()->where('requester_user_id', request()->user()->id)->first();
        $participant = $meeting->participants()->where('user_id', request()->user()->id)->first();

        return response()->json(['request' => $this->requestPayload($joinRequest, $participant), 'meeting_status' => $meeting->status->value]);
    }

    public function store(SchoolClass $schoolClass, Meeting $meeting, RequestMeetingJoin $action, MeetingAccess $access): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        if ($access->isAssignedEligibleHost(request()->user(), $meeting)) {
            return response()->json(['bypass' => true]);
        }
        $request = $action->handle(request()->user(), $meeting);

        $participant = $meeting->participants()->where('user_id', request()->user()->id)->first();

        return response()->json(['request' => $this->requestPayload($request, $participant)], $request->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(SchoolClass $schoolClass, Meeting $meeting, CancelMeetingJoinRequest $action): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        $request = $meeting->joinRequests()->where('requester_user_id', request()->user()->id)->firstOrFail();
        $cancelled = $action->handle(request()->user(), $request);

        return response()->json(['request' => $this->requestPayload($cancelled)]);
    }

    private function ensureScope(SchoolClass $schoolClass, Meeting $meeting): void
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
    }

    public static function requestPayload(?MeetingJoinRequest $request, ?MeetingParticipant $participant = null): ?array
    {
        return $request ? ['reference' => $request->public_uuid, 'status' => $request->status->value, 'requested_at' => $request->requested_at?->toIso8601String(), 'can_enter' => $request->admitsCurrentEntry($participant)] : null;
    }
}
