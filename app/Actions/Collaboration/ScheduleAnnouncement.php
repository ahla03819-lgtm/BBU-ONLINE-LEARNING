<?php

namespace App\Actions\Collaboration;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleAnnouncement
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Announcement $announcement, string $publishAt): Announcement
    {
        if (Carbon::parse($publishAt)->isPast()) {
            throw new \DomainException('Scheduled publication must be in the future.');
        }

        return DB::transaction(function () use ($announcement, $publishAt) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            if (! in_array($locked->status, [AnnouncementStatus::Draft, AnnouncementStatus::Scheduled], true)) {
                throw new \DomainException('Only draft or scheduled announcements may be scheduled.');
            }
            $before = $locked->only('status', 'publish_at');
            $locked->update(['status' => AnnouncementStatus::Scheduled, 'publish_at' => $publishAt, 'published_at' => null]);
            $this->audit->log('announcement.scheduled', $locked, $before, $locked->only('status', 'publish_at'));

            return $locked;
        });
    }
}
