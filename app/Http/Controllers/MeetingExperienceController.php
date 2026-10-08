<?php

namespace App\Http\Controllers;

use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingRecording;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MeetingAccess;
use App\Support\MeetingRecordingProjection;
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
        $viewer = request()->user();

        return [
            'schoolClass' => ['id' => $schoolClass->id, 'name' => $schoolClass->name, 'section' => $schoolClass->section],
            'meeting' => [
                'uuid' => $meeting->uuid, 'title' => $meeting->title,
                'status' => $meeting->status->value, 'lifecycle_version' => $meeting->lifecycle_version,
                'subject' => $meeting->classSubject?->subject?->only('code', 'name'),
                'host' => $meeting->host?->only('name'),
                'participant_reference' => $participant?->public_uuid,
                'join_request' => MeetingWaitingRoomController::requestPayload($meeting->joinRequests()->where('requester_user_id', request()->user()->id)->first()),
                'can_join' => request()->user()->can('join', $meeting),
                'can_bypass_waiting_room' => app(MeetingAccess::class)->isAssignedEligibleHost(request()->user(), $meeting),
                'can_screen_share' => request()->user()->can('screenShare', $meeting),
                'requires_screen_share_approval' => request()->user()->hasRole('Student'),
                'can_manage_screen_share_requests' => request()->user()->can('manageScreenShareRequests', $meeting),
                'can_end' => request()->user()->can('end', $meeting),
                'can_manage_participants' => request()->user()->can('removeParticipant', [$meeting]),
                'can_manage_join_requests' => request()->user()->can('manageJoinRequests', $meeting),
                'can_start_recording' => $viewer->can('startRecording', $meeting),
                'can_generate_ai_notes' => $viewer->can('generateAiNotes', $meeting),
                'can_generate_ai_summary' => $viewer->can('generateAiSummary', $meeting),
                // The very same bounds the request validates against, so the dialog
                // can reject an impossible duration before it is ever sent. The
                // server still validates independently.
                'recording_min_duration_minutes' => (int) config('meeting-recordings.min_duration_minutes'),
                'recording_max_duration_minutes' => (int) config('meeting-recordings.max_duration_minutes'),
                // The authoritative recording state, seeded on the page so the very
                // first paint already shows the correct indicator and countdown
                // instead of flashing an unrecorded room before a poll returns.
                'recording' => $this->recording($meeting, $viewer),
                'actual_start_at' => $meeting->actual_start_at?->toIso8601String(),
                'session_started_at' => $meeting->status === MeetingStatus::Active ? $meeting->session_started_at?->toIso8601String() : null,
                'schedule_available' => $meeting->hasValidScheduledInterval(),
                'scheduled_start_at' => $meeting->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $meeting->scheduled_end_at?->toIso8601String(),
                'actual_start_at' => $meeting->actual_start_at?->toIso8601String(),
                'actual_end_at' => $meeting->actual_end_at?->toIso8601String(),
                'invite_url' => route('meetings.lobby', [$schoolClass, $meeting], absolute: false),
            ],
        ];
    }

    /**
     * The recording state for this meeting, projected exactly as the realtime
     * broadcast and the polling fallback project it.
     *
     * A finished recording is not reported here: the room shows the state of the
     * capture in progress, and finished recordings live on the class channel card.
     */
    private function recording(Meeting $meeting, User $viewer): ?array
    {
        $recording = MeetingRecording::query()
            ->where('meeting_id', $meeting->id)
            ->whereIn('status', [
                MeetingRecordingStatus::Starting->value,
                MeetingRecordingStatus::Recording->value,
                MeetingRecordingStatus::Stopping->value,
                MeetingRecordingStatus::Processing->value,
            ])
            ->orderByDesc('id')
            ->first();

        if (! $recording) {
            return null;
        }

        return MeetingRecordingProjection::withPermissions(
            MeetingRecordingProjection::make($recording),
            $viewer->can('startRecording', $meeting),
            $viewer->can('stopRecording', [$meeting, $recording]),
        );
    }
}
