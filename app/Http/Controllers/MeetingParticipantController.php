<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\RemoveMeetingParticipant;
use App\Http\Requests\Meetings\RemoveMeetingParticipantRequest;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;

class MeetingParticipantController extends Controller
{
    public function index(SchoolClass $schoolClass, Meeting $meeting): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        $this->authorize('viewParticipants', $meeting);
        $canSeeRemoved = request()->user()->hasAnyRole(['Admin', 'Super Admin']) || request()->user()->id === $meeting->host_user_id;

        return response()->json(['participants' => $meeting->participants()->with('user:id,avatar_path')->orderBy('id')->get()->map(fn ($participant) => [
            'reference' => $participant->public_uuid,
            // Match connected participants without publishing provider identities or relying on names.
            'connection_key' => hash('sha256', $meeting->uuid.':'.$participant->livekit_identity),
            'display_name' => $participant->display_name_snapshot,
            'role' => $participant->role->value,
            'is_host' => $participant->user_id === $meeting->host_user_id,
            'can_remove' => $participant->user_id !== $meeting->host_user_id && request()->user()->can('remove', $participant),
            'present' => $participant->attendanceSessions()->whereNull('left_at')->exists(),
            'avatar_url' => $participant->user?->avatarUrl(),
            ...($canSeeRemoved ? ['removed' => (bool) $participant->removed_at] : []),
        ])]);
    }

    public function destroy(RemoveMeetingParticipantRequest $request, SchoolClass $schoolClass, Meeting $meeting, MeetingParticipant $participant, RemoveMeetingParticipant $action): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id && $participant->meeting_id === $meeting->id, 404);
        $removed = $action->handle($request->user(), $participant, $request->validated('reason'));

        return response()->json(['participant' => ['reference' => $removed->public_uuid, 'removed' => true]]);
    }
}
