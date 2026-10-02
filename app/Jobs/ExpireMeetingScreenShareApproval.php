<?php

namespace App\Jobs;

use App\Actions\Meetings\ReconcileMeetingScreenShareState;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingScreenShareReconciliation;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Events\MeetingScreenShareRequestChanged;
use App\Models\MeetingScreenShareRequest;
use App\Services\LiveKit\LiveKitRoomManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExpireMeetingScreenShareApproval implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $requestId) {}

    public function handle(LiveKitRoomManager $rooms, ReconcileMeetingScreenShareState $reconcile): void
    {
        $candidate = MeetingScreenShareRequest::query()->with(['meeting', 'participant'])->find($this->requestId);
        // The short window only guards the gap between approval and the student
        // pressing Start sharing. Once the share is live, or the request already
        // left the active state, this job is a no-op, so a delayed or re-delivered
        // job can never terminate a real share.
        if (! $candidate || ! $this->windowHasLapsed($candidate)) {
            return;
        }

        // Webhook delivery is known to be broken, so an absent track_published
        // webhook is not evidence that the student never shared. The provider is
        // asked directly, and that I/O happens outside any database lock.
        if ($reconcile->handle($candidate->meeting, $candidate->participant, $candidate) === MeetingScreenShareReconciliation::ProviderUnreachable) {
            throw new RuntimeException('Screen sharing approval state could not be confirmed; expiry is pending retry.');
        }

        DB::transaction(function () use ($rooms, $reconcile) {
            $locked = MeetingScreenShareRequest::query()->with(['meeting', 'participant'])->lockForUpdate()->find($this->requestId);
            if (! $locked || ! $this->windowHasLapsed($locked)) {
                return;
            }
            // Either reconcile() promoted the request to Sharing, which clears
            // expires_at, or it reported a lapsed live publication. Both must not
            // be expired as unused here.
            if (! $this->windowHasLapsed($locked)) {
                return;
            }
            $roomName = $locked->meeting->livekit_room_name;
            $identity = $locked->participant->livekit_identity;
            // A publication the provider can still see after the window lapsed is
            // an unauthorised one. Withdraw the grant, then contain the track by
            // the provider's own SID. LiveKit has no unpublish operation, so the
            // muted publication persists until the participant stops it.
            $trackSid = $reconcile->observedVideoTrackSid;
            if ($rooms->setParticipantScreenSharePermission($roomName, $identity, false) === MeetingProviderState::Unknown) {
                throw new RuntimeException('Screen sharing approval revocation is pending retry.');
            }
            if ($trackSid && $rooms->mutePublishedTrack($roomName, $identity, $trackSid) === MeetingProviderState::Unknown) {
                // Containment is not complete. Fail closed and retryable rather
                // than claiming the unauthorised publication was muted.
                throw new RuntimeException('Lapsed screen sharing approval is still published; mute is pending retry.');
            }
            $locked->update(['status' => MeetingScreenShareRequestStatus::Expired, 'active_slot' => null, 'completed_at' => now(), 'expires_at' => null]);
            MeetingScreenShareRequestChanged::dispatch($locked);
        });
    }

    /**
     * Only an Approved approval that was never used, and whose window has
     * lapsed, may be expired. A Sharing request, or any request that has already
     * left the active state, is never a candidate.
     */
    private function windowHasLapsed(MeetingScreenShareRequest $request): bool
    {
        return $request->status === MeetingScreenShareRequestStatus::Approved
            && ! $request->started_at
            && (bool) $request->expires_at
            && $request->expires_at->isPast();
    }
}
