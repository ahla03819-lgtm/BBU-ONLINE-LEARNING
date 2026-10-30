<?php

namespace App\Http\Controllers;

use App\Models\GuestMeetingInvitation;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Services\GuestMeetings\GuestMeetingInvitationService;
use Illuminate\Http\JsonResponse;

class GuestMeetingInvitationController extends Controller
{
    public function index(SchoolClass $schoolClass, Meeting $meeting): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        $this->authorize('manageGuestInvitations', $meeting);

        return response()->json([
            'invitations' => $meeting->guestInvitations()
                ->latest('id')
                ->get()
                ->map(fn (GuestMeetingInvitation $invitation) => $this->hostPayload($invitation))
                ->values(),
        ]);
    }

    public function store(SchoolClass $schoolClass, Meeting $meeting, GuestMeetingInvitationService $invitations): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        $issued = $invitations->create(request()->user(), $meeting);

        return response()->json([
            'invitation' => $this->hostPayload($issued->invitation),
            'invite_url' => route('guest.invitations.show', ['token' => $issued->token]),
        ], 201);
    }

    public function show(SchoolClass $schoolClass, Meeting $meeting, GuestMeetingInvitation $guestInvitation): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        abort_unless($guestInvitation->meeting_id === $meeting->id, 404);
        $this->authorize('manageGuestInvitations', $meeting);

        return response()->json(['invitation' => $this->hostPayload($guestInvitation)]);
    }

    public function destroy(SchoolClass $schoolClass, Meeting $meeting, GuestMeetingInvitation $guestInvitation, GuestMeetingInvitationService $invitations): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        abort_unless($guestInvitation->meeting_id === $meeting->id, 404);
        $revoked = $invitations->revoke(request()->user(), $guestInvitation);

        return response()->json(['invitation' => $this->hostPayload($revoked)]);
    }

    public function rotate(SchoolClass $schoolClass, Meeting $meeting, GuestMeetingInvitation $guestInvitation, GuestMeetingInvitationService $invitations): JsonResponse
    {
        $this->ensureScope($schoolClass, $meeting);
        abort_unless($guestInvitation->meeting_id === $meeting->id, 404);
        $issued = $invitations->rotate(request()->user(), $guestInvitation);

        return response()->json([
            'invitation' => $this->hostPayload($issued->invitation),
            'invite_url' => route('guest.invitations.show', ['token' => $issued->token]),
        ]);
    }

    private function ensureScope(SchoolClass $schoolClass, Meeting $meeting): void
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
    }

    private function hostPayload(GuestMeetingInvitation $invitation): array
    {
        return [
            'public_id' => $invitation->public_id,
            'version' => $invitation->version,
            'created_at' => $invitation->created_at?->toIso8601String(),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'rotated_at' => $invitation->rotated_at?->toIso8601String(),
            'status' => $invitation->revoked_at !== null
                ? 'revoked'
                : ($invitation->expires_at?->isPast() ? 'expired' : 'available'),
        ];
    }
}
