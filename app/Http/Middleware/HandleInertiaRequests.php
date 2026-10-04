<?php

namespace App\Http\Middleware;

use App\Models\UserNotification;
use App\Support\Locale;
use App\Support\UserNotificationPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        // The account preference is authoritative so the shell can render the
        // right language on the first paint and keep it across Inertia visits.
        App::setLocale(Locale::forUser($request->user()));

        return [
            ...parent::share($request),
            'locale' => fn () => Locale::forUser($request->user()),
            'calendar' => ['timezone' => config('calendar.default_timezone')],
            'auth' => ['user' => $request->user() ? [...$request->user()->only('id', 'name', 'email', 'status'), 'avatar_url' => $request->user()->avatarUrl()] : null, 'roles' => $request->user()?->getRoleNames() ?? [], 'role_label' => $request->user()?->effectiveRole(), 'permissions' => $request->user()?->hasRole('Super Admin') ? Permission::query()->orderBy('name')->pluck('name') : $request->user()?->getAllPermissions()->pluck('name') ?? []],
            'notificationInbox' => [
                'unread_count' => fn () => $request->user()?->can('notifications.view')
                    ? $request->user()->userNotifications()->whereNull('read_at')->count()
                    : 0,
                'preview' => fn () => $request->user()?->can('notifications.view')
                    ? UserNotification::query()
                        ->where('user_id', $request->user()->id)
                        ->with('actor:id,name,avatar_path')
                        ->latest('created_at')
                        ->latest('id')
                        ->limit(5)
                        ->get()
                        ->map(fn (UserNotification $notification) => UserNotificationPayload::make($notification, $request->user()))
                        ->values()
                    : [],
            ],
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
