<?php

namespace App\Policies;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\CollaborationAccess;

class ChannelPolicy
{
    public function __construct(private CollaborationAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('channels.view');
    }

    public function view(User $user, Channel $channel): bool
    {
        return $user->can('channels.view') && $this->access->canAccessChannel($user, $channel);
    }

    public function create(User $user, SchoolClass $class): bool
    {
        return $user->can('channels.create') && $this->access->canManageCustomChannel($user, $class);
    }

    public function update(User $user, Channel $channel): bool
    {
        return $user->can('channels.update') && $channel->type === ChannelType::Custom && $this->access->canManageCustomChannel($user, $channel->schoolClass);
    }

    public function archive(User $user, Channel $channel): bool
    {
        if (! $user->can('channels.archive')) {
            return false;
        }
        if ($channel->type === ChannelType::Custom) {
            return $this->access->canManageCustomChannel($user, $channel->schoolClass);
        }
        if ($channel->type === ChannelType::Subject) {
            return false;
        }

        return $this->access->isAdministrator($user) && ! $this->access->isAcademicallyActive($channel->schoolClass);
    }

    public function restore(User $user, Channel $channel): bool
    {
        if (! $user->can('channels.restore')) {
            return false;
        }
        if ($channel->type === ChannelType::Custom) {
            return $this->access->canManageCustomChannel($user, $channel->schoolClass);
        }
        if ($channel->type === ChannelType::Subject) {
            return false;
        }

        return $this->access->isAdministrator($user);
    }
}
