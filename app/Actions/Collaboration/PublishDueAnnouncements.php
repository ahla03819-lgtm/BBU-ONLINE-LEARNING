<?php

namespace App\Actions\Collaboration;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class PublishDueAnnouncements
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(int $chunkSize = 100): int
    {
        $published = 0;
        Announcement::query()->where('status', AnnouncementStatus::Scheduled->value)->where('publish_at', '<=', now())->orderBy('id')->chunkById($chunkSize, function ($announcements) use (&$published) {
            foreach ($announcements as $announcement) {
                DB::transaction(function () use ($announcement, &$published) {
                    $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
                    if ($locked->status !== AnnouncementStatus::Scheduled || $locked->publish_at?->isFuture()) {
                        return;
                    }$before = $locked->only('status', 'publish_at', 'published_at');
                    $locked->update(['status' => AnnouncementStatus::Published, 'published_at' => now()]);
                    $this->audit->log('announcement.published', $locked, $before, $locked->only('status', 'publish_at', 'published_at'));
                    $published++;
                });
            }
        });

        return $published;
    }
}
