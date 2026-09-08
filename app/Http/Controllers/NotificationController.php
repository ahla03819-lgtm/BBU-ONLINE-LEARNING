<?php

namespace App\Http\Controllers;

use App\Actions\Notifications\MarkAllUserNotificationsRead;
use App\Actions\Notifications\MarkUserNotificationRead;
use App\Models\AuditLog;
use App\Models\UserNotification;
use App\Support\AdminActivityPayload;
use App\Support\UserNotificationPayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', UserNotification::class);
        $notifications = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->with('actor:id,name,avatar_path')
            ->latest('created_at')
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (UserNotification $notification) => UserNotificationPayload::make($notification, $request->user()));

        $adminActivity = $request->user()->can('audit.view')
            ? AuditLog::query()
                ->whereIn('action', AdminActivityPayload::ACTIONS)
                ->with('actor:id,name,avatar_path')
                ->latest('created_at')
                ->latest('id')
                ->paginate(15, ['*'], 'admin_page')
                ->withQueryString()
                ->through(fn (AuditLog $log) => AdminActivityPayload::make($log, $request->user()))
            : null;

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'adminActivity' => $adminActivity,
        ]);
    }

    public function read(Request $request, string $notificationPublicId, MarkUserNotificationRead $action): RedirectResponse
    {
        $notification = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('public_id', $notificationPublicId)
            ->firstOrFail();
        $action->handle($request->user(), $notification);

        return back();
    }

    public function readAll(Request $request, MarkAllUserNotificationsRead $action): RedirectResponse
    {
        $action->handle($request->user());

        return back();
    }
}
