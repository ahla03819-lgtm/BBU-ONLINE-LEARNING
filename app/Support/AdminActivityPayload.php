<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AdminActivityPayload
{
    /**
     * Only operational records whose labels are safe without their audit
     * payload are suitable for the administrative activity feed. In
     * particular, private conversation, message, token, and provider events
     * deliberately never appear here.
     */
    public const ACTIONS = [
        'user.initial-super-admin-created', 'user.created', 'user.updated', 'user.deleted', 'user.status-changed', 'user.role-assigned', 'user.roles-changed', 'user.approved', 'user.password-reset', 'user.password-initialized', 'role.permissions-changed',
        'academic-year.created', 'academic-year.updated', 'grade-level.created', 'grade-level.updated', 'subject.created', 'subject.updated',
        'school-class.created', 'school-class.joined-by-code', 'class.subjects-synced',
        'enrollment.created', 'enrollment.ended', 'teacher-class.assigned', 'teacher-class.ended', 'teacher-class-subject.assigned', 'teacher-class-subject.ended',
        'assignment.created', 'assignment.updated', 'assignment.published', 'assignment.unpublished', 'assignment.published-corrected',
        'attendance.register-opened', 'attendance.register-finalized', 'attendance.record-corrected',
        'reporting-period.created', 'reporting-period.updated', 'reporting-period.transitioned',
        'meeting.created', 'meeting.start-succeeded', 'meeting.end-succeeded', 'meeting.cancelled', 'meeting.host-changed', 'meeting.super-admin-override',
        'meeting.join-request-admitted', 'meeting.join-request-denied',
        'announcement.created', 'announcement.updated', 'announcement.scheduled', 'announcement.published', 'announcement.archived', 'announcement.restored',
        'channel.created', 'channel.updated', 'channel.archived', 'channel.restored', 'channel.defaults-provisioned', 'channel.subject-provisioned',
    ];

    private const PRESENTATION = [
        'user.initial-super-admin-created' => ['created the initial Super Admin account', 'An initial Super Admin account was created', 'user', 'indigo'],
        'user.created' => ['created a user account', 'A user account was created', 'user', 'indigo'],
        'user.updated' => ['updated a user account', 'A user account was updated', 'user', 'indigo'],
        'user.deleted' => ['deleted a user account', 'A user account was deleted', 'user', 'amber'],
        'user.status-changed' => ['changed a user account status', 'A user account status changed', 'settings', 'amber'],
        'user.role-assigned' => ['assigned a user role', 'A user role was assigned', 'settings', 'indigo'],
        'user.roles-changed' => ["changed a user's role", "A user's role changed", 'settings', 'indigo'],
        'user.approved' => ['approved a university account', 'A university account was approved', 'user', 'green'],
        'user.password-reset' => ['reset a user password', 'A user password was reset', 'lock', 'amber'],
        'user.password-initialized' => ['completed initial password setup', 'Initial password setup was completed', 'lock', 'green'],
        'role.permissions-changed' => ['updated role permissions', 'Role permissions were updated', 'settings', 'indigo'],
        'academic-year.created' => ['created an academic year', 'An academic year was created', 'calendar', 'indigo'],
        'academic-year.updated' => ['updated an academic year', 'An academic year was updated', 'calendar', 'indigo'],
        'grade-level.created' => ['created a grade level', 'A grade level was created', 'book', 'indigo'],
        'grade-level.updated' => ['updated a grade level', 'A grade level was updated', 'book', 'indigo'],
        'subject.created' => ['created a subject', 'A subject was created', 'book', 'indigo'],
        'subject.updated' => ['updated a subject', 'A subject was updated', 'book', 'indigo'],
        'school-class.created' => ['created a class', 'A class was created', 'school', 'blue'],
        'school-class.joined-by-code' => ['approved a class join', 'A student joined a class', 'users', 'green'],
        'class.subjects-synced' => ['updated class subjects', 'Class subjects were updated', 'school', 'blue'],
        'enrollment.created' => ['enrolled a student in a class', 'A student was enrolled in a class', 'users', 'green'],
        'enrollment.ended' => ['ended a student enrollment', 'A student enrollment ended', 'users', 'amber'],
        'teacher-class.assigned' => ['assigned a teacher to a class', 'A teacher was assigned to a class', 'users', 'blue'],
        'teacher-class.ended' => ['ended a teacher class assignment', 'A teacher class assignment ended', 'users', 'amber'],
        'teacher-class-subject.assigned' => ['assigned a teacher to a subject', 'A teacher subject assignment was made', 'users', 'blue'],
        'teacher-class-subject.ended' => ['ended a teacher subject assignment', 'A teacher subject assignment ended', 'users', 'amber'],
        'assignment.created' => ['created coursework', 'Coursework was created', 'clipboard', 'indigo'],
        'assignment.updated' => ['updated coursework', 'Coursework was updated', 'clipboard', 'indigo'],
        'assignment.published' => ['published coursework', 'Coursework was published', 'clipboard', 'green'],
        'assignment.unpublished' => ['unpublished coursework', 'Coursework was unpublished', 'clipboard', 'amber'],
        'assignment.published-corrected' => ['corrected published coursework', 'Published coursework was corrected', 'clipboard', 'amber'],
        'attendance.register-opened' => ['opened an attendance register', 'An attendance register was opened', 'calendar', 'blue'],
        'attendance.register-finalized' => ['finalized an attendance register', 'An attendance register was finalized', 'calendar', 'green'],
        'attendance.record-corrected' => ['corrected an attendance record', 'An attendance record was corrected', 'calendar', 'amber'],
        'reporting-period.created' => ['created a reporting period', 'A reporting period was created', 'chart', 'blue'],
        'reporting-period.updated' => ['updated a reporting period', 'A reporting period was updated', 'chart', 'blue'],
        'reporting-period.transitioned' => ['changed a reporting period status', 'A reporting period status changed', 'chart', 'amber'],
        'meeting.created' => ['created a class meeting', 'A class meeting was created', 'video', 'blue'],
        'meeting.start-succeeded' => ['started a class meeting', 'A class meeting started', 'video', 'green'],
        'meeting.end-succeeded' => ['ended a class meeting', 'A class meeting ended', 'video', 'amber'],
        'meeting.cancelled' => ['cancelled a class meeting', 'A class meeting was cancelled', 'video', 'amber'],
        'meeting.host-changed' => ['changed a meeting host', 'A meeting host changed', 'video', 'indigo'],
        'meeting.super-admin-override' => ['used a meeting administration override', 'A meeting administration override was used', 'settings', 'amber'],
        'meeting.join-request-admitted' => ['admitted a meeting participant', 'A meeting participant was admitted', 'video', 'green'],
        'meeting.join-request-denied' => ['denied a meeting join request', 'A meeting join request was denied', 'video', 'amber'],
        'announcement.created' => ['created an announcement', 'An announcement was created', 'bell', 'amber'],
        'announcement.updated' => ['updated an announcement', 'An announcement was updated', 'bell', 'amber'],
        'announcement.scheduled' => ['scheduled an announcement', 'An announcement was scheduled', 'bell', 'amber'],
        'announcement.published' => ['published an announcement', 'An announcement was published', 'bell', 'green'],
        'announcement.archived' => ['archived an announcement', 'An announcement was archived', 'bell', 'amber'],
        'announcement.restored' => ['restored an announcement', 'An announcement was restored', 'bell', 'green'],
        'channel.created' => ['created a class channel', 'A class channel was created', 'messages', 'blue'],
        'channel.updated' => ['updated a class channel', 'A class channel was updated', 'messages', 'blue'],
        'channel.archived' => ['archived a class channel', 'A class channel was archived', 'messages', 'amber'],
        'channel.restored' => ['restored a class channel', 'A class channel was restored', 'messages', 'green'],
        'channel.defaults-provisioned' => ['set up class channels', 'Class channels were set up', 'messages', 'blue'],
        'channel.subject-provisioned' => ['set up a subject channel', 'A subject channel was set up', 'messages', 'blue'],
    ];

    public static function make(AuditLog $log, User $viewer): array
    {
        $presentation = self::presentation($log->action);
        $actor = $log->actor;

        return [
            'id' => $log->id,
            'action' => $log->action,
            'message' => $presentation[0],
            'fallback_message' => $presentation[1],
            'icon' => $presentation[2],
            'tone' => $presentation[3],
            'actor' => $actor ? ['name' => $actor->name, 'avatar_url' => $actor->avatarUrl()] : null,
            'resource' => self::resource($log, $viewer),
            'href' => self::href($log, $viewer),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private static function presentation(string $action): array
    {
        return self::PRESENTATION[$action] ?? ['updated administrative settings', 'Administrative settings were updated', 'settings', 'indigo'];
    }

    private static function resource(AuditLog $log, User $viewer): ?string
    {
        if ($log->target_type === User::class && $viewer->can('users.view')) {
            return User::query()->find($log->target_id)?->name;
        }

        if ($log->target_type === SchoolClass::class) {
            $schoolClass = SchoolClass::query()->find($log->target_id);

            return $schoolClass && Gate::forUser($viewer)->allows('view', $schoolClass)
                ? trim($schoolClass->name.' '.$schoolClass->section)
                : null;
        }

        if ($log->target_type === Meeting::class) {
            $meeting = Meeting::query()->find($log->target_id);

            return $meeting && Gate::forUser($viewer)->allows('view', $meeting)
                ? $meeting->title
                : null;
        }

        return null;
    }

    private static function href(AuditLog $log, User $viewer): ?string
    {
        if ($log->target_type === User::class && $viewer->can('users.view')) {
            return route('users.index', [], false);
        }

        if ($log->target_type === SchoolClass::class) {
            $schoolClass = SchoolClass::query()->find($log->target_id);

            return $schoolClass && Gate::forUser($viewer)->allows('view', $schoolClass)
                ? route('classes.show', $schoolClass, false)
                : null;
        }

        return null;
    }
}
