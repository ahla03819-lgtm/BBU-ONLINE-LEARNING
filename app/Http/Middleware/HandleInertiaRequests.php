<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => ['user' => $request->user()?->only('id', 'name', 'email', 'status'), 'roles' => $request->user()?->getRoleNames() ?? [], 'permissions' => $request->user()?->getAllPermissions()->pluck('name') ?? []],
            'notificationInbox' => [
                'unread_count' => fn () => $request->user()?->can('notifications.view')
                    ? $request->user()->userNotifications()->whereNull('read_at')->count()
                    : 0,
            ],
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
