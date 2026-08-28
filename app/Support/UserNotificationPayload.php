<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationDeepLinkResolver;
use Illuminate\Support\Arr;

class UserNotificationPayload
{
    private const LABELS = [
        'announcement.published' => 'New announcement',
        'meeting.scheduled' => 'Meeting scheduled',
        'meeting.started' => 'Meeting started',
        'meeting.cancelled' => 'Meeting cancelled',
        'meeting.participant-removed' => 'Meeting access changed',
        'assignment.published' => 'New assignment',
        'assignment.submitted' => 'Assignment submitted',
        'assignment.graded' => 'Assignment graded',
    ];

    private const CONTEXT_KEYS = [
        'announcement.published' => ['title'],
        'meeting.scheduled' => ['title', 'scheduled_start_at'],
        'meeting.started' => ['title'],
        'meeting.cancelled' => ['title'],
        'meeting.participant-removed' => ['message'],
        'assignment.published' => ['title'],
        'assignment.submitted' => ['assignment_title'],
        'assignment.graded' => ['assignment_title'],
    ];

    public static function make(UserNotification $notification, User $viewer): array
    {
        $href = app(NotificationDeepLinkResolver::class)->resolve($viewer, $notification);
        $mayExposeContext = $href !== null || $notification->type === 'meeting.participant-removed';

        return [
            'public_id' => $notification->public_id,
            'type' => $notification->type,
            'label' => self::LABELS[$notification->type] ?? 'Account notification',
            'context' => $mayExposeContext
                ? Arr::only($notification->context ?? [], self::CONTEXT_KEYS[$notification->type] ?? [])
                : [],
            'href' => $href,
            'is_read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
