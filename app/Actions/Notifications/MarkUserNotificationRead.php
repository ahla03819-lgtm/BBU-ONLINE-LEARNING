<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Gate;

class MarkUserNotificationRead
{
    public function handle(User $user, UserNotification $notification): UserNotification
    {
        Gate::forUser($user)->authorize('markRead', $notification);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification->refresh();
    }
}
