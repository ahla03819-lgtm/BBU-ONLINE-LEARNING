<?php

namespace App\Jobs;

use App\Services\GuestMeetings\GuestParticipantRemovalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A prompt, bounded attempt; the scheduled reconciler remains the recovery path. */
class ReconcileGuestMeetingParticipantRemoval implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $removalId) {}

    public function handle(GuestParticipantRemovalService $removals): void
    {
        $result = $removals->attempt($this->removalId);
        if ($result['retry_at']) {
            self::dispatch($this->removalId)->delay($result['retry_at']);
        }
    }
}
