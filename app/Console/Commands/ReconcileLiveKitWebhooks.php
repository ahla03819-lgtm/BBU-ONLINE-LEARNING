<?php

namespace App\Console\Commands;

use App\Enums\LiveKitWebhookStatus;
use App\Jobs\ProcessLiveKitWebhook;
use App\Models\LiveKitWebhookEvent;
use Illuminate\Console\Command;

class ReconcileLiveKitWebhooks extends Command
{
    protected $signature = 'meetings:reconcile-webhooks {--dry-run}';

    protected $description = 'Retry pending LiveKit webhook events in deterministic order';

    public function handle(): int
    {
        $count = 0;
        LiveKitWebhookEvent::query()->where('status', LiveKitWebhookStatus::Pending)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('occurred_at')->orderBy('event_id')->chunkById(100, function ($events) use (&$count) {
                foreach ($events as $event) {
                    $count++;
                    if (! $this->option('dry-run')) {
                        ProcessLiveKitWebhook::dispatchSync($event->event_id);
                    }
                }
            }, 'event_id');
        $this->info("Eligible webhook events: $count");

        return self::SUCCESS;
    }
}
