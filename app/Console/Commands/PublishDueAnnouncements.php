<?php

namespace App\Console\Commands;

use App\Actions\Collaboration\PublishDueAnnouncements as PublishDueAnnouncementsAction;
use Illuminate\Console\Command;

class PublishDueAnnouncements extends Command
{
    protected $signature = 'announcements:publish-due {--chunk=100}';

    protected $description = 'Publish scheduled announcements whose publication time has arrived';

    public function handle(PublishDueAnnouncementsAction $action): int
    {
        $published = $action->handle(max(1, (int) $this->option('chunk')));
        $this->info("Published {$published} due announcements.");

        return self::SUCCESS;
    }
}
