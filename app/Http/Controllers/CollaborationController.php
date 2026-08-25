<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Channel;
use App\Models\SchoolClass;
use App\Services\CollaborationAccess;
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

    public function workspace(SchoolClass $schoolClass, CollaborationAccess $access, ?Channel $channel = null): Response
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

        return Inertia::render('Collaboration/Workspace', ['schoolClass' => $schoolClass->load('academicYear:id,name,status', 'gradeLevel:id,name'), 'channels' => $channels, 'channel' => $selected, 'announcements' => $announcements, 'canCreateChannel' => auth()->user()->can('create', [Channel::class, $schoolClass]), 'canCreateAnnouncement' => $selected ? auth()->user()->can('create', [Announcement::class, $selected]) : false, 'isAdministrator' => $access->isAdministrator(auth()->user())]);
    }
}
