<?php

namespace App\Actions\Collaboration;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class PublishAnnouncement
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Announcement $announcement, ?\DateTimeInterface $publishedAt = null): Announcement
    {
        return DB::transaction(function () use ($announcement, $publishedAt) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($locked->status === AnnouncementStatus::Published) {
                return $locked;
            }
            if (! in_array($locked->status, [AnnouncementStatus::Draft, AnnouncementStatus::Scheduled], true)) {
                throw new \DomainException('Only draft or scheduled announcements may be published.');
            }
            $before = $locked->only('status', 'publish_at', 'published_at');
            $locked->update(['status' => AnnouncementStatus::Published, 'published_at' => $publishedAt ?? now()]);
            $this->audit->log('announcement.published', $locked, $before, $locked->only('status', 'publish_at', 'published_at'));

            return $locked;
        });
    }
}
