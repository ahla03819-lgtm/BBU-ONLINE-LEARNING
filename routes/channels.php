<?php

use App\Models\Channel;
use App\Models\SchoolClass;
use App\Services\CollaborationAccess;
use App\Services\MeetingAccess;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('collaboration.channel.{channelId}', function ($user, int $channelId) {
    if (! $user->isActive() || ! $user->hasVerifiedEmail() || ! $user->can('messages.view')) {
        return false;
    }
    $channel = Channel::query()->find($channelId);
    if (! $channel || ! app(CollaborationAccess::class)->canAccessChannel($user, $channel)) {
        return false;
    }
    $participantType = $user->hasRole('Teacher') ? 'teacher' : ($user->hasRole('Student') ? 'student' : 'staff');

    return ['id' => $user->id, 'name' => $user->name, 'participant_type' => $participantType];
});

Broadcast::channel('meetings.class.{schoolClassId}', function ($user, int $schoolClassId) {
    $schoolClass = SchoolClass::query()->find($schoolClassId);

    return $schoolClass && app(MeetingAccess::class)->canAccessClass($user, $schoolClass);
});
