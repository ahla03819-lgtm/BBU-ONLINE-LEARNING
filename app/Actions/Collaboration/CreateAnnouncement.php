<?php

namespace App\Actions\Collaboration;

use App\Enums\AnnouncementStatus;
use App\Enums\ChannelType;
use App\Models\Announcement;
use App\Models\Channel;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateAnnouncement
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Channel $channel, array $data): Announcement
    {
        if (! in_array($channel->type, [ChannelType::Announcement, ChannelType::Subject], true)) {
            throw new \DomainException('Announcements are only supported in announcement and subject channels.');
        }

        return DB::transaction(function () use ($channel, $data) {
            $announcement = Announcement::query()->create(['channel_id' => $channel->id, 'author_id' => auth()->id(), 'title' => $data['title'], 'body' => $data['body'], 'status' => AnnouncementStatus::Draft, 'expires_at' => $data['expires_at'] ?? null, 'pinned_at' => ! empty($data['is_pinned']) ? now() : null]);
            $this->audit->log('announcement.created', $announcement, [], $announcement->only('channel_id', 'author_id', 'title', 'status', 'expires_at', 'pinned_at'));

            return $announcement;
        });
    }
}
