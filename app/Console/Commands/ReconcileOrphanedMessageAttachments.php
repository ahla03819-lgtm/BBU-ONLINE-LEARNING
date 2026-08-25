<?php

namespace App\Console\Commands;

use App\Models\MessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ReconcileOrphanedMessageAttachments extends Command
{
    protected $signature = 'attachments:reconcile-orphans {--dry-run : Report without deleting} {--hours= : Minimum object age}';

    protected $description = 'Remove old private message attachment objects that have no database row';

    public function handle(): int
    {
        $diskName = config('message-attachments.disk');
        $disk = Storage::disk($diskName);
        $minimumAge = max(1, (int) ($this->option('hours') ?: config('message-attachments.orphan_minimum_age_hours')));
        $cutoff = now()->subHours($minimumAge)->timestamp;
        $removed = 0;
        foreach ($disk->allFiles('message-attachments') as $path) {
            if ($disk->lastModified($path) > $cutoff || MessageAttachment::query()->where('disk', $diskName)->where('path', $path)->exists()) {
                continue;
            }
            $this->line(($this->option('dry-run') ? 'Would remove: ' : 'Removing: ').$path);
            if (! $this->option('dry-run') && $disk->delete($path)) {
                $removed++;
            }
        }
        $this->info($this->option('dry-run') ? 'Dry run complete.' : "Removed {$removed} orphaned object(s).");

        return self::SUCCESS;
    }
}
