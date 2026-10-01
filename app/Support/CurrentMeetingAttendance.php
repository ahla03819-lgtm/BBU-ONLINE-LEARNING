<?php

namespace App\Support;

use App\Models\MeetingAttendanceSession;

/**
 * The attendance session that matches the participant's CURRENT provider
 * presence, together with the authoritative LiveKit participant SID the
 * provider reported for it.
 *
 * Callers must verify the session really carries that SID and is still open: a
 * session for a different SID is stale history and never proves the participant
 * is connected right now.
 */
final readonly class CurrentMeetingAttendance
{
    public function __construct(
        public MeetingAttendanceSession $session,
        public string $participantSid,
    ) {}

    public function isCurrentFor(MeetingAttendanceSession $candidate): bool
    {
        return $candidate->left_at === null
            && $candidate->livekit_participant_sid === $this->participantSid;
    }
}
