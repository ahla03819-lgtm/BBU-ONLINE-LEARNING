<?php

namespace App\Actions\Collaboration;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateAnnouncement
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Announcement $announcement, array $data): Announcement
    {
        return DB::transaction(function () use ($announcement, $data) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            if (! in_array($locked->status, [AnnouncementStatus::Draft, AnnouncementStatus::Scheduled], true)) {
                throw new \DomainException('Only draft or scheduled announcements may be edited.');
            }
            $before = $locked->only('title', 'status', 'publish_at', 'expires_at', 'pinned_at');
            $locked->update(['title' => $data['title'], 'body' => $data['body'], 'expires_at' => $data['expires_at'] ?? null, 'pinned_at' => ! empty($data['is_pinned']) ? ($locked->pinned_at ?? now()) : null]);
            $this->audit->log('announcement.updated', $locked, $before, $locked->only('title', 'status', 'publish_at', 'expires_at', 'pinned_at'));

            return $locked;
        });
    }
}
