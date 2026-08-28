<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserNotification;

class UserNotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->eligible($user) && $user->can('notifications.view');
    }

    public function view(User $user, UserNotification $notification): bool
    {
        return $this->viewAny($user) && $notification->user_id === $user->id;
    }

    public function markRead(User $user, UserNotification $notification): bool
    {
        return $this->eligible($user)
            && $user->can('notifications.mark-read')
            && $notification->user_id === $user->id;
    }

    public function markAllRead(User $user): bool
    {
        return $this->eligible($user) && $user->can('notifications.mark-read');
    }

    private function eligible(User $user): bool
    {
        return $user->isActive() && $user->hasVerifiedEmail();
    }
}
