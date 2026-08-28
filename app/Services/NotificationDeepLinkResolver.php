<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Channel;
use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Gate;

class NotificationDeepLinkResolver
{
    public function resolve(User $user, UserNotification $notification): ?string
    {
        return match ($notification->route_name) {
            'collaboration.channels.show' => $this->collaboration($user, $notification->route_parameters ?? []),
            'meetings.show' => $this->meeting($user, $notification->route_parameters ?? []),
            'coursework.assignments.show' => $this->assignment($user, $notification->route_parameters ?? []),
            default => null,
        };
    }

    private function collaboration(User $user, array $parameters): ?string
    {
        $class = SchoolClass::query()->find($parameters['schoolClass'] ?? null);
        $channel = Channel::query()
            ->whereKey($parameters['channel'] ?? null)
            ->where('school_class_id', $class?->id)
            ->first();

        return $class && $channel && Gate::forUser($user)->allows('view', $channel)
            ? route('collaboration.channels.show', [$class, $channel], false)
            : null;
    }

    private function meeting(User $user, array $parameters): ?string
    {
        $class = SchoolClass::query()->find($parameters['schoolClass'] ?? null);
        $meeting = Meeting::query()
            ->where('uuid', $parameters['meeting'] ?? null)
            ->where('school_class_id', $class?->id)
            ->first();

        return $class && $meeting && Gate::forUser($user)->allows('view', $meeting)
            ? route('meetings.show', [$class, $meeting], false)
            : null;
    }

    private function assignment(User $user, array $parameters): ?string
    {
        $class = SchoolClass::query()->find($parameters['schoolClass'] ?? null);
        $subject = ClassSubject::query()
            ->whereKey($parameters['classSubject'] ?? null)
            ->where('school_class_id', $class?->id)
            ->first();
        $assignment = Assignment::query()
            ->whereKey($parameters['assignment'] ?? null)
            ->where('class_subject_id', $subject?->id)
            ->first();

        return $class && $subject && $assignment && Gate::forUser($user)->allows('view', $assignment)
            ? route('coursework.assignments.show', [$class, $subject, $assignment], false)
            : null;
    }
}
