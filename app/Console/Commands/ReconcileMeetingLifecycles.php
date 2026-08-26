<?php

namespace App\Console\Commands;

use App\Actions\Meetings\ReconcileMeetingLifecycle;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use Illuminate\Console\Command;

class ReconcileMeetingLifecycles extends Command
{
    protected $signature = 'meetings:reconcile-lifecycle {--dry-run : Inspect stranded transitions without mutation}';

    protected $description = 'Reconcile meetings stranded in starting or ending against the configured provider boundary';

    public function handle(ReconcileMeetingLifecycle $action): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $inspected = 0;
        $changed = 0;

        Meeting::query()
            ->whereIn('status', [MeetingStatus::Starting->value, MeetingStatus::Ending->value])
            ->orderBy('id')
            ->eachById(function (Meeting $meeting) use ($action, $dryRun, &$inspected, &$changed) {
                $inspected++;
                $before = $meeting->lifecycle_version;
                $result = $action->handle($meeting, $dryRun);
                if ($result->lifecycle_version !== $before) {
                    $changed++;
                }
            });

        $this->info(($dryRun ? 'Dry run inspected ' : 'Reconciliation inspected ').$inspected." meeting(s); {$changed} changed.");

        return self::SUCCESS;
    }
}
