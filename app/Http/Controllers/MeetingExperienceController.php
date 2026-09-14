<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Services\MeetingAccess;
use Inertia\Inertia;
use Inertia\Response;

class MeetingExperienceController extends Controller
{
    public function lobby(SchoolClass $schoolClass, Meeting $meeting, MeetingAccess $access): Response
    {
        $this->authorizePage($schoolClass, $meeting, $access, false);

        return Inertia::render('Meetings/Lobby', $this->props($schoolClass, $meeting));
    }

    public function room(SchoolClass $schoolClass, Meeting $meeting, MeetingAccess $access): Response
    {
        $this->authorizePage($schoolClass, $meeting, $access, true);

        return Inertia::render('Meetings/Room', $this->props($schoolClass, $meeting));
    }

    private function authorizePage(SchoolClass $schoolClass, Meeting $meeting, MeetingAccess $access, bool $requireActive): void
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        abort_unless($access->canAccessMeeting(request()->user(), $meeting), 403);
        abort_if($meeting->participants()->where('user_id', request()->user()->id)->whereNotNull('removed_at')->exists(), 403);
        if ($requireActive) {
            $this->authorize('join', $meeting);
        }
    }

    private function props(SchoolClass $schoolClass, Meeting $meeting): array
    {
        $meeting->loadMissing(['classSubject.subject:id,code,name', 'host:id,name']);
        $participant = $meeting->participants()->where('user_id', request()->user()->id)->first();

        return [
            'schoolClass' => ['id' => $schoolClass->id, 'name' => $schoolClass->name, 'section' => $schoolClass->section],
            'meeting' => [
                'uuid' => $meeting->uuid, 'title' => $meeting->title,
                'status' => $meeting->status->value, 'lifecycle_version' => $meeting->lifecycle_version,
                'subject' => $meeting->classSubject?->subject?->only('code', 'name'),
                'host' => $meeting->host?->only('name'),
                'participant_reference' => $participant?->public_uuid,
                'join_request' => MeetingWaitingRoomController::requestPayload($meeting->joinRequests()->where('requester_user_id', request()->user()->id)->first(), $participant),
                'can_join' => request()->user()->can('join', $meeting),
                'can_bypass_waiting_room' => app(MeetingAccess::class)->isAssignedEligibleHost(request()->user(), $meeting),
                'can_screen_share' => request()->user()->can('screenShare', $meeting),
                'can_end' => request()->user()->can('end', $meeting),
                'can_manage_participants' => request()->user()->can('removeParticipant', [$meeting]),
                'can_manage_join_requests' => request()->user()->can('manageJoinRequests', $meeting),
                'actual_start_at' => $meeting->actual_start_at?->toIso8601String(),
            ],
        ];
    }
}
