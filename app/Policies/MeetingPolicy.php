<?php

namespace App\Policies;

use App\Enums\MeetingScreenShareRequestStatus;
use App\Enums\MeetingStatus;
use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\MeetingRecording;
use App\Models\MeetingScreenShareRequest;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MeetingAccess;

class MeetingPolicy
{
    public function __construct(private MeetingAccess $access) {}

    public function view(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.view') && $this->access->canAccessMeeting($user, $meeting);
    }

    public function create(User $user, SchoolClass $schoolClass, ?ClassSubject $classSubject = null): bool
    {
        return $user->can('meetings.create') && $this->access->canCreateMeeting($user, $schoolClass, $classSubject);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.update')
            && $meeting->status === MeetingStatus::Scheduled
            && $this->access->canManageMeeting($user, $meeting);
    }

    public function cancel(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.cancel')
            && in_array($meeting->status, [MeetingStatus::Scheduled, MeetingStatus::Cancelled], true)
            && $this->access->canManageMeeting($user, $meeting);
    }

    public function start(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.start')
            && in_array($meeting->status, [MeetingStatus::Scheduled, MeetingStatus::Starting, MeetingStatus::Active], true)
            && $this->access->canManageMeeting($user, $meeting)
            && $this->access->isAssignedEligibleHost($user, $meeting);
    }

    public function end(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.end')
            && in_array($meeting->status, [MeetingStatus::Active, MeetingStatus::Ending, MeetingStatus::Ended], true)
            && $this->access->canManageMeeting($user, $meeting)
            && $this->access->isAssignedEligibleHost($user, $meeting);
    }

    public function join(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.join')
            && $meeting->status === MeetingStatus::Active
            && $this->access->canParticipateInMeeting($user, $meeting)
            && ! $this->isRemoved($user, $meeting);
    }

    public function viewParticipants(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.participants.view')
            && $this->access->canAccessMeeting($user, $meeting);
    }

    public function removeParticipant(User $user, Meeting $meeting, ?MeetingParticipant $participant = null): bool
    {
        if (! $user->can('meetings.participants.remove')
            || $meeting->status !== MeetingStatus::Active
            || ! $this->access->canManageMeeting($user, $meeting)
            || (! $this->access->isAdministrator($user) && ! $this->access->isAssignedEligibleHost($user, $meeting))) {
            return false;
        }

        return ! $participant
            || ($participant->meeting_id === $meeting->id && ! $participant->removed_at && $participant->user_id !== $user->id);
    }

    public function muteParticipant(User $user, Meeting $meeting, ?MeetingParticipant $participant = null): bool
    {
        if (! $this->removeParticipant($user, $meeting)) {
            return false;
        }

        return ! $participant
            || ($participant->meeting_id === $meeting->id
                && ! $participant->removed_at
                && $participant->user_id !== $user->id
                && $participant->user_id !== $meeting->host_user_id);
    }

    public function issueToken(User $user, Meeting $meeting): bool
    {
        if (! ($user->can('meetings.tokens.issue')
            && $meeting->status === MeetingStatus::Active
            && $this->access->canParticipateInMeeting($user, $meeting)
            && ! $this->isRemoved($user, $meeting))) {
            return false;
        }

        if ($this->access->isAssignedEligibleHost($user, $meeting)) {
            return true;
        }

        $request = $meeting->joinRequests()->where('requester_user_id', $user->id)->first();

        return $request?->admitsCurrentEntry() ?? false;
    }

    public function manageJoinRequests(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.participants.remove')
            && $meeting->status === MeetingStatus::Active
            && $this->access->isAssignedEligibleHost($user, $meeting);
    }

    public function screenShare(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.screen-share')
            && $meeting->status === MeetingStatus::Active
            && $this->access->canParticipateInMeeting($user, $meeting)
            && ($this->access->isAdministrator($user) || $user->hasRole('Teacher'))
            && ! $this->isRemoved($user, $meeting);
    }

    /**
     * The static half of the requestScreenShare check.
     *
     * Live presence is deliberately NOT part of any policy. A local open
     * attendance row is not proof that the participant is connected: webhook
     * delivery is known to be broken, so participant_joined may never have
     * opened the row and participant_left may never close it. Treating such a
     * row as authorization evidence is exactly the stale-presence bug this flow
     * exists to prevent, so presence is established by EnsureCurrentMeetingAttendance
     * against the LiveKit Room Service, which a policy may never call.
     *
     * RequestMeetingScreenShare is the only production caller of this method, and
     * it verifies provider-current presence (exact participant SID, session still
     * open) before authorizing. This policy therefore gates structure and access
     * only, and cannot be satisfied by a stale attendance row.
     */
    public function requestScreenShare(User $user, Meeting $meeting, MeetingParticipant $participant): bool
    {
        return $this->requestScreenShareStructure($user, $meeting, $participant)
            && $this->access->canParticipateInMeeting($user, $meeting);
    }

    /**
     * The purely structural half of requestScreenShare: role, live meeting,
     * participant ownership and non-removal. Used to decide whether it is worth
     * asking the provider to confirm presence at all.
     */
    public function requestScreenShareStructure(User $user, Meeting $meeting, MeetingParticipant $participant): bool
    {
        return $user->hasRole('Student')
            && $meeting->status === MeetingStatus::Active
            && $participant->meeting_id === $meeting->id
            && $participant->user_id === $user->id
            && ! $participant->removed_at;
    }

    /**
     * Only the student who owns the request may ask the server to re-check it.
     *
     * Possession of the public reference is deliberately not enough: the
     * requester, the participant and the authenticated user must all be the same
     * person, the participant must still be in the meeting, and the request must
     * still be an approval in flight. A host or teacher must not be able to use
     * this endpoint to drive a student's lifecycle.
     */
    public function reconcileScreenShareRequest(User $user, Meeting $meeting, MeetingParticipant $participant, MeetingScreenShareRequest $request): bool
    {
        return $user->hasRole('Student')
            && $meeting->status === MeetingStatus::Active
            && $request->meeting_id === $meeting->id
            && $request->meeting_participant_id === $participant->id
            && $request->requester_user_id === $user->id
            && $participant->user_id === $user->id
            && $participant->meeting_id === $meeting->id
            && ! $participant->removed_at
            && in_array($request->status, [MeetingScreenShareRequestStatus::Approved, MeetingScreenShareRequestStatus::Sharing], true);
    }

    public function manageScreenShareRequests(User $user, Meeting $meeting): bool
    {
        return $meeting->status === MeetingStatus::Active
            && ($this->access->isAdministrator($user) || $user->hasRole('Teacher'))
            && $this->access->canManageMeeting($user, $meeting);
    }

    public function decideScreenShareRequest(User $user, Meeting $meeting, MeetingScreenShareRequest $request): bool
    {
        return $request->meeting_id === $meeting->id
            && $request->requester_user_id !== $user->id
            && $this->manageScreenShareRequests($user, $meeting);
    }

    public function completeScreenShareRequest(User $user, Meeting $meeting, MeetingScreenShareRequest $request): bool
    {
        return $request->meeting_id === $meeting->id
            && ($request->requester_user_id === $user->id || $this->manageScreenShareRequests($user, $meeting));
    }

    public function reconcile(User $user, Meeting $meeting): bool
    {
        if (! in_array($meeting->status, [MeetingStatus::Starting, MeetingStatus::Ending], true)
            || ! $this->access->canManageMeeting($user, $meeting)) {
            return false;
        }

        if ($this->access->isAdministrator($user)) {
            return $user->can($meeting->status === MeetingStatus::Starting ? 'meetings.start' : 'meetings.end');
        }

        return $this->access->isAssignedEligibleHost($user, $meeting)
            && $user->can($meeting->status === MeetingStatus::Starting ? 'meetings.start' : 'meetings.end');
    }

    public function viewTranscript(User $user, Meeting $meeting): bool
    {
        return $this->access->canAccessMeetingAi($user, $meeting);
    }

    public function viewAiNotes(User $user, Meeting $meeting): bool
    {
        return $this->access->canAccessMeetingAi($user, $meeting);
    }

    public function viewAiSummary(User $user, Meeting $meeting): bool
    {
        return $this->access->canAccessMeetingAi($user, $meeting);
    }

    public function generateAiNotes(User $user, Meeting $meeting): bool
    {
        return $this->access->canManageMeetingAi($user, $meeting);
    }

    public function generateAiSummary(User $user, Meeting $meeting): bool
    {
        return $this->access->canManageMeetingAi($user, $meeting);
    }

    /**
      * Recording is a host capability, not a moderation one.
     *
     * The permission is the primary gate, so a student cannot reach it at all even
     * though a student may legitimately be able to manage participants. On top of
     * that the recording must belong to a live meeting the user may manage, and
     * only a meeting's assigned eligible host may record it. A teacher assigned to
     * a different class fails on all three counts.
     */
    public function startRecording(User $user, Meeting $meeting): bool
    {
        return $user->can('meetings.record')
            && $meeting->status === MeetingStatus::Active
            && $this->access->canManageMeeting($user, $meeting)
            && ($this->access->isAdministrator($user) || $this->access->isAssignedEligibleHost($user, $meeting))
            && ! $this->isRemoved($user, $meeting);
    }

    /**
     * Stopping is granted to anyone who could have started the recording, so a
     * meeting cannot be left with a capture nobody in the room is allowed to end.
     * Possession of the recording is checked by the caller; this gates the ability.
     */
    public function stopRecording(User $user, Meeting $meeting, MeetingRecording $recording): bool
    {
        return $recording->meeting_id === $meeting->id
            && $this->startRecording($user, $meeting);
    }

    private function isRemoved(User $user, Meeting $meeting): bool
    {
        return $meeting->participants()
            ->where('user_id', $user->id)
            ->whereNotNull('removed_at')
            ->exists();
    }
}
