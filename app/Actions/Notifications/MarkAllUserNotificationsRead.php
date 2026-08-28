<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Gate;

class MarkAllUserNotificationsRead
{
    public function handle(User $user): int
    {
        Gate::forUser($user)->authorize('markAllRead', UserNotification::class);

        return UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }
}
