<?php

namespace App\Console\Commands;

use App\Models\AssignmentSubmissionAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ReconcileOrphanedCourseworkAttachments extends Command
{
    protected $signature = 'coursework:reconcile-attachments {--dry-run : Report without deleting} {--hours= : Minimum object age}';

    protected $description = 'Remove old private coursework attachment objects that have no database row';

    public function handle(): int
    {
        $diskName = config('coursework-attachments.disk');
        $disk = Storage::disk($diskName);
        $cutoff = now()->subHours(max(1, (int) ($this->option('hours') ?: config('coursework-attachments.orphan_minimum_age_hours'))))->timestamp;
        $removed = 0;
        foreach ($disk->allFiles('coursework-submissions') as $path) {
            if ($disk->lastModified($path) > $cutoff || AssignmentSubmissionAttachment::query()->where('disk', $diskName)->where('path', $path)->exists()) {
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
