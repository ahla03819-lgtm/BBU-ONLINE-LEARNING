<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\LiveKit\LiveKitParticipantPresence;
use App\Services\LiveKit\LiveKitRoomManager;
use App\Services\MeetingAccess;
use App\Support\CurrentMeetingAttendance;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Establishes that the authenticated student is connected to the meeting right
 * now, and reconciles the attendance session for that exact presence.
 *
 * A local open attendance row is deliberately NOT sufficient proof. Webhook
 * delivery is known to be broken, so participant_joined may never have arrived
 * and participant_left may never arrive either: an open row can therefore
 * outlive the participant's actual departure. Every call therefore asks the
 * LiveKit Room Service for current presence, and only a confirmed Active
 * participant with a participant SID authorises anything. Ended and Unknown
 * both fail closed.
 *
 * The room name and the identity are always taken from the Meeting and the
 * MeetingParticipant. Nothing in this path is derived from client input.
 */
final class EnsureCurrentMeetingAttendance
{
    public function __construct(private LiveKitRoomManager $rooms, private MeetingAccess $access) {}

    /**
     * @throws ValidationException when presence cannot be confirmed
     */
    public function ensure(User $user, Meeting $meeting, MeetingParticipant $participant): CurrentMeetingAttendance
    {
        $this->authorizeStaticFacts($user, $meeting, $participant);

        // Provider network I/O runs before any transaction or row lock, so a slow
        // or unreachable provider can never hold a database lock open.
        $presence = $this->rooms->participantPresence($meeting->livekit_room_name, $participant->livekit_identity);
        if (! $presence->isPresent() || $presence->participantSid === null) {
            $this->deny();
        }

        return $this->reconcile($participant, $presence);
    }

    /**
     * Server-side facts that need no provider call. The policy owns the role,
     * ownership and removal rules so the request path and this action can never
     * drift apart.
     */
    private function authorizeStaticFacts(User $user, Meeting $meeting, MeetingParticipant $participant): void
    {
        Gate::forUser($user)->authorize('requestScreenShareStructure', [$meeting, $participant]);

        if ($meeting->status !== MeetingStatus::Active
            || ! $meeting->livekit_room_name
            || ! $participant->livekit_identity
            || ! $this->access->canParticipateInMeeting($user, $meeting)) {
            $this->deny();
        }
    }

    /**
     * Short transaction, taken only after presence is proven. It locks the
     * participant row so two concurrent requests cannot open competing sessions,
     * then keys on the provider participant SID.
     *
     * LiveKit issues a new participant SID for every connection, so the same SID
     * always refers to the same presence episode. That makes
     * (meeting_participant_id, livekit_participant_sid) the correct idempotency
     * key and means a row for the current SID is never legitimately closed: if
     * one is, the evidence contradicts itself and the request is refused rather
     * than having history rewritten.
     *
     * An open row for a DIFFERENT SID is stale: it is left untouched and is
     * never used as proof of presence. The participant may hold two open rows
     * for a moment, which is preferable to inventing a departure time that never
     * happened.
     */
    private function reconcile(MeetingParticipant $participant, LiveKitParticipantPresence $presence): CurrentMeetingAttendance
    {
        return DB::transaction(function () use ($participant, $presence) {
            MeetingParticipant::query()->lockForUpdate()->findOrFail($participant->id);

            $session = $this->currentSession($participant, $presence->participantSid);
            if ($session && $session->left_at !== null) {
                $this->deny();
            }

            if (! $session) {
                try {
                    // join_webhook_event_id stays null: no webhook backs this row.
                    // A real participant_joined webhook for this SID adopts it.
                    $session = MeetingAttendanceSession::query()->create([
                        'meeting_participant_id' => $participant->id,
                        'livekit_participant_sid' => $presence->participantSid,
                        'join_webhook_event_id' => null,
                        'joined_at' => $presence->joinedAt ?? now(),
                        'left_at' => null,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // The webhook path created the row first. Both paths take the
                    // same participant row lock, so this is only reachable if a
                    // writer outside them inserted the row; adopting it is the
                    // same outcome the webhook itself would have produced.
                    $session = $this->currentSession($participant, $presence->participantSid);
                    if (! $session || $session->left_at !== null) {
                        $this->deny();
                    }
                }
            }

            return new CurrentMeetingAttendance($session, $presence->participantSid);
        });
    }

    private function currentSession(MeetingParticipant $participant, string $participantSid): ?MeetingAttendanceSession
    {
        return MeetingAttendanceSession::query()
            ->where('meeting_participant_id', $participant->id)
            ->where('livekit_participant_sid', $participantSid)
            ->lockForUpdate()
            ->first();
    }

    /**
     * One generic message for every denial, so the response never reveals
     * whether the provider was unreachable or the participant was absent.
     */
    private function deny(): never
    {
        throw ValidationException::withMessages(['screen_share' => 'You must be present in the meeting to request screen sharing.']);
    }
}
