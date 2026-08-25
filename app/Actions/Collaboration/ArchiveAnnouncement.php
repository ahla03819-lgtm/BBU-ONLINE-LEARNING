<?php

namespace App\Actions\Collaboration;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ArchiveAnnouncement
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Announcement $announcement): Announcement
    {
        return DB::transaction(function () use ($announcement) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($locked->status === AnnouncementStatus::Archived) {
                return $locked;
            }$before = $locked->only('status', 'archived_at', 'archived_by');
            $locked->update(['status' => AnnouncementStatus::Archived, 'archived_at' => now(), 'archived_by' => auth()->id()]);
            $this->audit->log('announcement.archived', $locked, $before, $locked->only('status', 'archived_at', 'archived_by'));

            return $locked;
        });
    }
}
