<?php

namespace App\Actions\Collaboration;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class RestoreAnnouncement
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Announcement $announcement): Announcement
    {
        return DB::transaction(function () use ($announcement) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($locked->status !== AnnouncementStatus::Archived) {
                return $locked;
            }$before = $locked->only('status', 'archived_at', 'archived_by');
            $locked->update(['status' => AnnouncementStatus::Draft, 'archived_at' => null, 'archived_by' => null, 'published_at' => null, 'publish_at' => null]);
            $this->audit->log('announcement.restored', $locked, $before, $locked->only('status'));

            return $locked;
        });
    }
}
