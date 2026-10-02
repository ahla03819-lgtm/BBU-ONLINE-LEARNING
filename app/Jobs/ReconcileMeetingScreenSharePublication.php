<?php

namespace App\Jobs;

use App\Actions\Meetings\ReconcileMeetingScreenShareState;
use App\Enums\MeetingScreenShareReconciliation;
use App\Models\MeetingScreenShareRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bounded server-side retry of the post-gesture reconciliation.
 *
 * LiveKit's control plane can lag briefly behind a local track publication, so a
 * single inspection taken right after the student pressed Start sharing may not
 * see the track yet. This job re-inspects a few times with a short backoff and
 * then stops. It never fabricates state: an unreachable provider throws so the
 * queue retries, and a definitive "not sharing" simply ends the attempt because
 * the expiry job remains the authoritative final check.
 */
class ReconcileMeetingScreenSharePublication implements ShouldQueue
{
    use Queueable;

    /** Attempts are bounded so this can never become a polling loop. */
    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [3, 10, 25];

    public function __construct(public int $requestId) {}

    public function handle(ReconcileMeetingScreenShareState $reconcile): void
    {
        $request = MeetingScreenShareRequest::query()->with(['meeting', 'participant'])->find($this->requestId);
        if (! $request || $request->started_at || ! $request->status->isActive()) {
            return;
        }

        $outcome = $reconcile->handle($request->meeting, $request->participant, $request);

        if ($outcome === MeetingScreenShareReconciliation::ProviderUnreachable
            || $outcome === MeetingScreenShareReconciliation::DefinitelyNotSharing) {
            // Both retry: one because the provider could not be asked, the other
            // because control-plane visibility can lag the local publication by a
            // moment. Bounded by tries/backoff, never an open loop.
            throw new \RuntimeException('Screen sharing publication is not visible to the provider yet.');
        }

        // SharingConfirmed is a no-op success. LapsedLivePublication stops here:
        // the expiry job owns containment of an unauthorised publication.
    }
}
