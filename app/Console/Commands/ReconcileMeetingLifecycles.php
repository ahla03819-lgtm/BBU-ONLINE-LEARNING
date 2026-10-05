<?php

namespace App\Console\Commands;

use App\Actions\Meetings\ReconcileMeetingLifecycle;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use Illuminate\Console\Command;

class ReconcileMeetingLifecycles extends Command
{
    protected $signature = 'meetings:reconcile-lifecycle {--dry-run : Inspect stranded transitions without mutation}';

    protected $description = 'Reconcile meetings stranded in starting, ending or active against the configured provider boundary';

    public function handle(ReconcileMeetingLifecycle $action): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Each state is reported on its own because they mean different things: a
        // stranded start and a stranded end are transitions that were already in
        // flight, while an Active meeting is a meeting nobody ever closed. An
        // Active row the backstop is not allowed to touch is counted too, so a
        // deliberately disabled backstop is visible rather than silently absent.
        $tracked = [
            MeetingStatus::Starting->value => ['inspected' => 0, 'recovered' => 0],
            MeetingStatus::Ending->value => ['inspected' => 0, 'recovered' => 0],
            MeetingStatus::Active->value => ['inspected' => 0, 'recovered' => 0],
        ];
        $skipped = 0;

        Meeting::query()
            ->whereIn('status', array_keys($tracked))
            ->orderBy('id')
            ->eachById(function (Meeting $meeting) use ($action, $dryRun, &$tracked, &$skipped) {
                $status = $meeting->status->value;
                $tracked[$status]['inspected']++;
                if ($meeting->status === MeetingStatus::Active && ! $action->activeRecoveryEnabledFor($meeting)) {
                    $skipped++;

                    return;
                }
                $before = $meeting->lifecycle_version;
                $result = $action->handle($meeting, $dryRun);
                if ($result->lifecycle_version !== $before) {
                    $tracked[$status]['recovered']++;
                }
            });

        foreach ($tracked as $status => $counts) {
            $this->line(sprintf('%s: inspected %d, recovered %d', $status, $counts['inspected'], $counts['recovered']));
        }
        $this->line('skipped: '.$skipped.' active meeting(s) outside the configured recovery window.');

        $this->info(($dryRun ? 'Dry run inspected ' : 'Reconciliation inspected ')
            .array_sum(array_column($tracked, 'inspected'))." meeting(s); "
            .array_sum(array_column($tracked, 'recovered')).' recovered.');

        return self::SUCCESS;
    }
}
