<?php

namespace App\Console\Commands;

use App\Enums\MeetingSeriesStatus;
use App\Models\MeetingSeries;
use App\Services\Meetings\MeetingOccurrenceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplenishMeetingSeriesOccurrences extends Command
{
    protected $signature = 'meetings:replenish-series {--chunk=100}';

    protected $description = 'Extend active open-ended meeting series through the configured rolling horizon';

    public function handle(MeetingOccurrenceGenerator $generator): int
    {
        $processed = 0;

        MeetingSeries::query()
            ->where('status', MeetingSeriesStatus::Active->value)
            ->whereNull('ends_on')
            ->orderBy('id')
            ->chunkById(max(1, (int) $this->option('chunk')), function ($series) use ($generator, &$processed): void {
                foreach ($series as $item) {
                    DB::transaction(function () use ($item, $generator): void {
                        $locked = MeetingSeries::query()->lockForUpdate()->findOrFail($item->id);
                        $today = CarbonImmutable::now($locked->timezone)->startOfDay();
                        $through = $today->addDays((int) config('calendar.generation_horizon_days'));
                        $generator->generateThroughLocked($locked, $through, $today);
                    });
                    $processed++;
                }
            });

        $this->info("Replenished {$processed} open-ended meeting series.");

        return self::SUCCESS;
    }
}
