<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\CompleteMeetingScreenShareRequest;
use App\Actions\Meetings\DecideMeetingScreenShareRequest;
use App\Actions\Meetings\ReconcileMeetingScreenShareState;
use App\Actions\Meetings\RequestMeetingScreenShare;
use App\Enums\MeetingScreenShareReconciliation;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Http\Requests\Meetings\DecideMeetingScreenShareRequestRequest;
use App\Jobs\ReconcileMeetingScreenSharePublication;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\MeetingScreenShareRequest;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;

class MeetingScreenShareRequestController extends Controller
{
    public function index(SchoolClass $schoolClass, Meeting $meeting): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        $this->authorize('viewParticipants', $meeting);
        $user = request()->user();
        $current = $meeting->screenShareRequests()->where('requester_user_id', $user->id)->latest('id')->first();
        $requests = $user->can('manageScreenShareRequests', $meeting)
            ? $meeting->screenShareRequests()->with('requester:id,name,avatar_path')->where('status', MeetingScreenShareRequestStatus::Pending)->orderBy('requested_at')->get()->map(fn ($request) => $this->payload($request, true))->values()
            : collect();

        return response()
            ->json(['current' => $current ? $this->payload($current) : null, 'requests' => $requests])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(SchoolClass $schoolClass, Meeting $meeting, RequestMeetingScreenShare $action): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        $request = $action->handle(request()->user(), $meeting);

        return response()->json(['request' => $this->payload($request)], 201);
    }

    public function update(DecideMeetingScreenShareRequestRequest $httpRequest, SchoolClass $schoolClass, Meeting $meeting, MeetingScreenShareRequest $screenShareRequest, DecideMeetingScreenShareRequest $action): JsonResponse
    {
        $this->ensureRequestScope($schoolClass, $meeting, $screenShareRequest);
        $request = $action->handle($httpRequest->user(), $screenShareRequest, MeetingScreenShareRequestStatus::from($httpRequest->validated('decision')));

        return response()->json(['request' => $this->payload($request)]);
    }

    public function destroy(SchoolClass $schoolClass, Meeting $meeting, MeetingScreenShareRequest $screenShareRequest, CompleteMeetingScreenShareRequest $action): JsonResponse
    {
        $this->ensureRequestScope($schoolClass, $meeting, $screenShareRequest);
        $request = $action->handle(request()->user(), $screenShareRequest);

        return response()->json(['request' => $this->payload($request)]);
    }

    /**
     * Triggered by the student's explicit Start sharing click, after the local
     * LiveKit publication has succeeded.
     *
     * The browser sends no LiveKit metadata at all: no room name, no identity,
     * no participant SID, no track SID, no track source. Only the opaque request
     * reference and the usual route scope are accepted, and the server derives
     * every provider identifier itself.
     *
     * Control-plane visibility can lag the local publication, so an immediate
     * miss is handed to a bounded queued retry rather than resolved inline. The
     * two-minute expiry job remains the authoritative final check.
     */
    public function reconcile(SchoolClass $schoolClass, Meeting $meeting, MeetingScreenShareRequest $screenShareRequest, ReconcileMeetingScreenShareState $reconcile): JsonResponse
    {
        $this->ensureRequestScope($schoolClass, $meeting, $screenShareRequest);
        $participant = MeetingParticipant::query()
            ->where('meeting_id', $meeting->id)
            ->where('user_id', request()->user()->id)
            ->firstOrFail();
        $this->authorize('reconcileScreenShareRequest', [$meeting, $participant, $screenShareRequest]);

        $outcome = $reconcile->handle($meeting, $participant, $screenShareRequest);

        // Truthful per outcome. LapsedLivePublication is deliberately NOT
        // reported as retrying: containment is owned by the expiry job, so no
        // retry job is dispatched here and the client is told nothing about the
        // provider's view of the room.
        if ($outcome === MeetingScreenShareReconciliation::SharingConfirmed) {
            return response()->json(['reconciled' => true, 'retrying' => false], 200);
        }

        if ($outcome === MeetingScreenShareReconciliation::LapsedLivePublication) {
            return response()->json(['reconciled' => false, 'retrying' => false], 200);
        }

        // DefinitelyNotSharing: control-plane visibility may still be catching up
        // with the local publication. ProviderUnreachable: we could not ask. Both
        // hand off to the bounded retry rather than resolving inline.
        ReconcileMeetingScreenSharePublication::dispatch($screenShareRequest->id);

        return response()->json(['reconciled' => false, 'retrying' => true], 202);
    }

    private function ensureScope(SchoolClass $schoolClass, Meeting $meeting): void
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
    }

    private function ensureRequestScope(SchoolClass $schoolClass, Meeting $meeting, MeetingScreenShareRequest $request): void
    {
        $this->ensureScope($schoolClass, $meeting);
        abort_unless($request->meeting_id === $meeting->id, 404);
    }

    private function payload(MeetingScreenShareRequest $request, bool $includeRequester = false): array
    {
        return [
            'reference' => $request->public_uuid,
            'status' => $request->status->value,
            'requested_at' => $request->requested_at?->toIso8601String(),
            'expires_at' => $request->expires_at?->toIso8601String(),
            ...($includeRequester ? [
                'display_name' => $request->requester->name,
                'avatar_url' => $request->requester->avatarUrl(),
            ] : []),
        ];
    }
}
