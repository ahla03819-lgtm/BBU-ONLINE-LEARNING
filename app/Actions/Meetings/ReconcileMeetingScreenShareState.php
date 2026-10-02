<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingProviderState;
use App\Enums\MeetingScreenShareReconciliation;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Events\MeetingScreenShareRequestChanged;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\MeetingScreenShareRequest;
use App\Services\LiveKit\LiveKitRoomManager;
use App\Services\LiveKit\LiveKitScreenShareState;
use Illuminate\Support\Facades\DB;

/**
 * Decides a screen-share request's lifecycle from authoritative provider state.
 *
 * The track_published webhook stays the primary signal, but webhook delivery is
 * known to be broken, so "no webhook arrived" must never be read as "the
 * student never shared". This action asks the LiveKit Room Service directly and
 * is the fallback that keeps a live share from being expired.
 *
 * Every provider identifier is server-derived: the room name comes from the
 * Meeting and the identity from the MeetingParticipant. A client may ask for a
 * reconciliation by public reference alone, and can never supply a room, an
 * identity, a participant SID or a track SID.
 */
final class ReconcileMeetingScreenShareState
{
    public function __construct(private LiveKitRoomManager $rooms) {}

    public function handle(Meeting $meeting, MeetingParticipant $participant, MeetingScreenShareRequest $request): MeetingScreenShareReconciliation
    {
        $this->authorize($meeting, $participant, $request);

        // Provider network I/O runs before any transaction or row lock, so a slow
        // or unreachable LiveKit can never hold a database lock open.
        $state = $this->rooms->screenShareState($meeting->livekit_room_name, $participant->livekit_identity);

        if ($state->state === MeetingProviderState::Unknown) {
            // Not a verdict. The caller must retry and must not treat this as
            // "the student never shared".
            return MeetingScreenShareReconciliation::ProviderUnreachable;
        }

        if (! $state->isSharing()) {
            return MeetingScreenShareReconciliation::DefinitelyNotSharing;
        }

        return $this->confirmSharing($request, $state);
    }

    /**
     * Short transaction taken only after the provider proved a live share.
     *
     * The provider report is authoritative about a publication that exists right
     * now, but it is NOT authoritative about authorisation. An approval that has
     * already lapsed is therefore reported as a lapsed live publication and left
     * untouched, so a student who publishes after the host's window closed is
     * contained rather than legitimised.
     *
     * The request row is re-read under a lock so a concurrent webhook, expiry job
     * or explicit-start reconciliation cannot produce a second transition.
     */
    private function confirmSharing(MeetingScreenShareRequest $request, LiveKitScreenShareState $state): MeetingScreenShareReconciliation
    {
        $outcome = DB::transaction(function () use ($request) {
            $locked = MeetingScreenShareRequest::query()->lockForUpdate()->find($request->id);
            if (! $locked) {
                return MeetingScreenShareReconciliation::DefinitelyNotSharing;
            }
            // Already sharing, or already terminal. Never resurrect, never
            // regress: Sharing must not fall back to Approved or forward to
            // Expired because two observers disagreed.
            if ($locked->status->isSharing() || ! $locked->status->isActive() || $locked->started_at) {
                return MeetingScreenShareReconciliation::SharingConfirmed;
            }

            // The approval window is a real deadline, so a publication first
            // observed after it lapsed is unauthorised and must be contained,
            // not promoted.
            if ($locked->expires_at?->isPast()) {
                return MeetingScreenShareReconciliation::LapsedLivePublication;
            }

            $locked->update([
                'status' => MeetingScreenShareRequestStatus::Sharing,
                'started_at' => now(),
                'expires_at' => null,
                'active_slot' => 1,
            ]);
            // After-commit broadcast, matching the webhook path.
            MeetingScreenShareRequestChanged::dispatch($locked);

            return MeetingScreenShareReconciliation::SharingConfirmed;
        });

        // The provider track SID is never persisted: no authoritative storage
        // field exists for it, and it is returned to the caller so containment
        // can use the provider's own SID rather than anything a client sent.
        $this->observedVideoTrackSid = $state->videoTrackSid;

        return $outcome;
    }

    /**
     * The provider's canonical SCREEN_SHARE VIDEO track SID from the most recent
     * inspection, when one was published. Never sourced from a client.
     */
    public ?string $observedVideoTrackSid = null;

    private function authorize(Meeting $meeting, MeetingParticipant $participant, MeetingScreenShareRequest $request): void
    {
        abort_if($request->meeting_id !== $meeting->id, 404);
        abort_if($request->meeting_participant_id !== $participant->id, 404);
        abort_if($participant->meeting_id !== $meeting->id, 404);
        abort_if($participant->removed_at, 403);
        abort_if(! $meeting->livekit_room_name || ! $participant->livekit_identity, 422);
    }
}
