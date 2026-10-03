<?php

namespace App\Console\Commands;

use App\Actions\Recordings\ReconcileMeetingRecordingState;
use Illuminate\Console\Command;

class ReconcileMeetingRecordings extends Command
{
    protected $signature = 'meetings:reconcile-recordings {--dry-run : Inspect overdue recordings without mutating them}';

    protected $description = 'Stop meeting recordings whose scheduled deadline passed and finalize recordings still settling';

    public function handle(ReconcileMeetingRecordingState $reconcile): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $reconcile->handle($dryRun);

        $this->info(sprintf(
            '%s inspected recordings: %d overdue, %d settling.',
            $dryRun ? 'Dry run' : 'Reconciliation',
            $result['stopped'],
            $result['finalized'],
        ));

        return self::SUCCESS;
    }
}