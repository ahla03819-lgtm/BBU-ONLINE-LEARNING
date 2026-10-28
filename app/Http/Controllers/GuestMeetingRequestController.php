<?php

namespace App\Http\Controllers;

use App\Enums\GuestMeetingSessionStatus;
use App\Http\Requests\GuestMeetings\DecideGuestMeetingSessionRequest;
use App\Models\GuestMeetingSession;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Services\GuestMeetings\GuestMeetingSessionService;
use Illuminate\Http\JsonResponse;

class GuestMeetingRequestController extends Controller
{
    public function index(SchoolClass $schoolClass, Meeting $meeting): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        $this->authorize('manageGuestInvitations', $meeting);

        $requests = $meeting->guestSessions()
            ->with('invitation')
            ->where('status', GuestMeetingSessionStatus::Pending)
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->orderBy('requested_at')
            ->get()
            ->filter(fn (GuestMeetingSession $session) => app(GuestMeetingSessionService::class)->state($session) === 'pending')
            ->map(fn (GuestMeetingSession $session) => $this->payload($session))
            ->values();

        return response()->json(['requests' => $requests]);
    }

    public function update(DecideGuestMeetingSessionRequest $request, SchoolClass $schoolClass, Meeting $meeting, GuestMeetingSession $guestSession, GuestMeetingSessionService $sessions): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        abort_unless($guestSession->meeting_id === $meeting->id, 404);
        $decided = $sessions->decide($request->user(), $guestSession, GuestMeetingSessionStatus::from($request->validated('decision')));

        return response()->json(['request' => $this->payload($decided)]);
    }

    private function ensureScope(SchoolClass $schoolClass, Meeting $meeting): void
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
    }

    private function payload(GuestMeetingSession $session): array
    {
        return [
            'reference' => $session->public_uuid,
            'display_name' => $session->display_name_snapshot,
            'status' => $session->status->value,
            'requested_at' => $session->requested_at?->toIso8601String(),
            'participant_type' => 'guest',
        ];
    }
}
