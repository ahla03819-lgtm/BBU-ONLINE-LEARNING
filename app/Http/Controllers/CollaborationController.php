<?php

namespace App\Http\Controllers;

use App\Enums\MeetingStatus;
use App\Models\Announcement;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SchoolClass;
use App\Services\CollaborationAccess;
use App\Services\MeetingAccess;
use Inertia\Inertia;
use Inertia\Response;

class CollaborationController extends Controller
{
    public function index(CollaborationAccess $access): Response
    {
        $this->authorize('viewAny', Channel::class);
        $classes = $access->classesFor(auth()->user())->with(['academicYear:id,name,status', 'gradeLevel:id,name'])->withCount(['channels' => fn ($query) => $access->isAdministrator(auth()->user()) ? $query : $query->active()])->orderBy('name')->get();

        return Inertia::render('Collaboration/Index', ['classes' => $classes]);
    }

    public function workspace(SchoolClass $schoolClass, CollaborationAccess $access, MeetingAccess $meetingAccess, ?Channel $channel = null): Response
    {
        abort_unless(auth()->user()->can('channels.view') && $access->canAccessClass(auth()->user(), $schoolClass), 403);
        if ($channel) {
            abort_unless($channel->school_class_id === $schoolClass->id && $access->canAccessChannel(auth()->user(), $channel), 404);
        }
        $channels = $access->channelsFor(auth()->user())->where('school_class_id', $schoolClass->id)->with('classSubject.subject')->orderByRaw('default_slot is null, default_slot')->orderBy('name')->get();
        $selected = $channel ?: $channels->first();
        $announcements = collect();
        if ($selected && auth()->user()->can('announcements.view')) {
            $query = $selected->announcements()->with('author:id,name')->latest('published_at')->latest();
            $announcements = $access->isAdministrator(auth()->user()) ? $query->get() : $query->activeFeed()->get();
        }
        $featuredMeeting = null;
        if (auth()->user()->can('meetings.view') && $meetingAccess->canAccessClass(auth()->user(), $schoolClass)) {
            $meeting = $meetingAccess->meetingsFor(auth()->user())
                ->where('school_class_id', $schoolClass->id)
                ->whereIn('status', [MeetingStatus::Active->value, MeetingStatus::Starting->value, MeetingStatus::Ending->value, MeetingStatus::Scheduled->value])
                ->with(['host:id,name', 'classSubject.subject:id,code,name'])
                ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'starting' THEN 2 WHEN 'ending' THEN 3 ELSE 4 END")
                ->orderBy('scheduled_start_at')
                ->first();
            if ($meeting) {
                $featuredMeeting = [
                    'uuid' => $meeting->uuid,
                    'title' => $meeting->title,
                    'status' => $meeting->status->value,
                    'scheduled_start_at' => $meeting->scheduled_start_at?->toIso8601String(),
                    'host' => $meeting->host?->only('name'),
                    'subject' => $meeting->classSubject?->subject?->only('code', 'name'),
                    'can_join' => auth()->user()->can('join', $meeting),
                    'can_start' => auth()->user()->can('start', $meeting),
                ];
            }
        }

        return Inertia::render('Collaboration/Workspace', ['schoolClass' => $schoolClass->load('academicYear:id,name,status', 'gradeLevel:id,name'), 'channels' => $channels, 'channel' => $selected, 'announcements' => $announcements, 'featuredMeeting' => $featuredMeeting, 'canViewMeetings' => auth()->user()->can('meetings.view') && $meetingAccess->canAccessClass(auth()->user(), $schoolClass), 'canCreateChannel' => auth()->user()->can('create', [Channel::class, $schoolClass]), 'canCreateAnnouncement' => $selected ? auth()->user()->can('create', [Announcement::class, $selected]) : false, 'canCreateMessage' => $selected ? auth()->user()->can('create', [Message::class, $selected]) : false, 'canModerateMessages' => $selected ? auth()->user()->can('messages.moderate') && ($access->isAdministrator(auth()->user()) || $access->isCurrentClassTeacher(auth()->user(), $schoolClass)) : false, 'isAdministrator' => $access->isAdministrator(auth()->user())]);
    }
}
