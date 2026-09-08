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
        'conversation.direct-message' => 'New direct message',
        'conversation.group-message' => 'New group message',
        'conversation.member-added' => 'Added to a group',
        'conversation.member-removed' => 'Group access changed',
        'conversation.member-promoted' => 'You are now a group manager',
        'conversation.call-missed' => 'Missed call',
        'conversation.call-declined' => 'Call declined',
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
        'conversation.direct-message' => ['actor_name'],
        'conversation.group-message' => ['conversation_name'],
        'conversation.member-added' => ['conversation_name'],
        'conversation.member-removed' => ['message'],
        'conversation.member-promoted' => ['conversation_name'],
        'conversation.call-missed' => ['actor_name', 'call_type'],
        'conversation.call-declined' => ['actor_name', 'call_type'],
    ];

    private const PRESENTATION = [
        'announcement.published' => ['icon' => 'messages', 'tone' => 'blue'],
        'meeting.scheduled' => ['icon' => 'calendar', 'tone' => 'blue'],
        'meeting.started' => ['icon' => 'video', 'tone' => 'green'],
        'meeting.cancelled' => ['icon' => 'video', 'tone' => 'red'],
        'meeting.participant-removed' => ['icon' => 'video', 'tone' => 'amber'],
        'assignment.published' => ['icon' => 'clipboard', 'tone' => 'indigo'],
        'assignment.submitted' => ['icon' => 'clipboard', 'tone' => 'blue'],
        'assignment.graded' => ['icon' => 'chart', 'tone' => 'green'],
        'conversation.direct-message' => ['icon' => 'messages', 'tone' => 'indigo'],
        'conversation.group-message' => ['icon' => 'users', 'tone' => 'blue'],
        'conversation.member-added' => ['icon' => 'users', 'tone' => 'green'],
        'conversation.member-removed' => ['icon' => 'users', 'tone' => 'amber'],
        'conversation.member-promoted' => ['icon' => 'users', 'tone' => 'indigo'],
        'conversation.call-missed' => ['icon' => 'video', 'tone' => 'red'],
        'conversation.call-declined' => ['icon' => 'video', 'tone' => 'amber'],
    ];

    public static function make(UserNotification $notification, User $viewer): array
    {
        $href = app(NotificationDeepLinkResolver::class)->resolve($viewer, $notification);
        $mayExposeContext = $href !== null || in_array($notification->type, ['meeting.participant-removed', 'conversation.member-removed'], true);
        $actor = $mayExposeContext ? $notification->actor : null;
        $presentation = self::PRESENTATION[$notification->type] ?? ['icon' => 'bell', 'tone' => 'indigo'];

        return [
            'public_id' => $notification->public_id,
            'type' => $notification->type,
            'label' => self::LABELS[$notification->type] ?? 'Account notification',
            'icon' => $presentation['icon'],
            'tone' => $presentation['tone'],
            'actor' => $actor ? ['name' => $actor->name, 'avatar_url' => $actor->avatarUrl()] : null,
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
