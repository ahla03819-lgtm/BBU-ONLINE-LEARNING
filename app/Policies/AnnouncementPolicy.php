<?php

namespace App\Policies;

use App\Enums\AnnouncementStatus;
use App\Enums\ChannelType;
use App\Models\Announcement;
use App\Models\Channel;
use App\Models\User;
use App\Services\CollaborationAccess;

class AnnouncementPolicy
{
    public function __construct(private CollaborationAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('announcements.view');
    }

    public function view(User $user, Announcement $announcement): bool
    {
        if (! $user->can('announcements.view') || ! $this->access->canAccessChannel($user, $announcement->channel)) {
            return false;
        }
        if ($this->access->isAdministrator($user)) {
            return true;
        }

        return $announcement->status === AnnouncementStatus::Published && $announcement->published_at?->isPast() && (! $announcement->expires_at || $announcement->expires_at->isFuture());
    }

    public function create(User $user, Channel $channel): bool
    {
        return $user->can('announcements.create') && in_array($channel->type, [ChannelType::Announcement, ChannelType::Subject], true) && $this->access->canAuthorIn($user, $channel);
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $user->can('announcements.update') && in_array($announcement->status, [AnnouncementStatus::Draft, AnnouncementStatus::Scheduled], true) && $this->canManageAuthored($user, $announcement);
    }

    public function publish(User $user, Announcement $announcement): bool
    {
        return $user->can('announcements.publish') && in_array($announcement->status, [AnnouncementStatus::Draft, AnnouncementStatus::Scheduled], true) && $this->canManageAuthored($user, $announcement);
    }

    public function archive(User $user, Announcement $announcement): bool
    {
        return $user->can('announcements.archive') && $announcement->status !== AnnouncementStatus::Archived && $this->canManageAuthored($user, $announcement);
    }

    public function restore(User $user, Announcement $announcement): bool
    {
        return $user->can('announcements.restore') && $this->access->isAdministrator($user) && $announcement->status === AnnouncementStatus::Archived;
    }

    private function canManageAuthored(User $user, Announcement $announcement): bool
    {
        return $this->access->isAdministrator($user) || ($announcement->author_id === $user->id && $this->access->canAuthorIn($user, $announcement->channel));
    }
}
