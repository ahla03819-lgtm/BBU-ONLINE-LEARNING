<?php

namespace App\Console\Commands;

use App\Services\GuestMeetings\GuestMeetingSessionService;
use App\Services\GuestMeetings\GuestParticipantRemovalService;
use Illuminate\Console\Command;

class ReconcileGuestMeetingSessions extends Command
{
    protected $signature = 'guest-meetings:reconcile {--limit= : Maximum sessions/removals per pass}';

    protected $description = 'Expire guest meeting sessions and reconcile durable LiveKit participant removals';

    public function handle(GuestMeetingSessionService $sessions, GuestParticipantRemovalService $removals): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('guest-meetings.reconciliation_batch_size', 50)));
        $expired = $sessions->expireDue($limit);
        $result = $removals->reconcileDue($limit);
        $this->info(sprintf('Expired %d guest session(s); inspected %d removal(s), attempted %d, resolved %d.', $expired, $result['inspected'], $result['attempted'], $result['resolved']));

        return self::SUCCESS;
    }
}
