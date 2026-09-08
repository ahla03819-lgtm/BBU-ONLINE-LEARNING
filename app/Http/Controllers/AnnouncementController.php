<?php

namespace App\Http\Controllers;

use App\Actions\Collaboration\ArchiveAnnouncement;
use App\Actions\Collaboration\CreateAnnouncement;
use App\Actions\Collaboration\PublishAnnouncement;
use App\Actions\Collaboration\RestoreAnnouncement;
use App\Actions\Collaboration\ScheduleAnnouncement;
use App\Actions\Collaboration\UpdateAnnouncement;
use App\Http\Requests\Collaboration\ArchiveAnnouncementRequest;
use App\Http\Requests\Collaboration\CreateAnnouncementRequest;
use App\Http\Requests\Collaboration\PublishAnnouncementRequest;
use App\Http\Requests\Collaboration\RestoreAnnouncementRequest;
use App\Http\Requests\Collaboration\ScheduleAnnouncementRequest;
use App\Http\Requests\Collaboration\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Models\Channel;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function create(SchoolClass $schoolClass, Channel $channel): Response
    {
        $this->ensureChannel($schoolClass, $channel);
        $this->authorize('create', [Announcement::class, $channel]);

        return Inertia::render('Collaboration/Announcements/Create', ['schoolClass' => $schoolClass, 'channel' => $channel]);
    }

    public function store(CreateAnnouncementRequest $request, SchoolClass $schoolClass, Channel $channel, CreateAnnouncement $action): RedirectResponse
    {
        $this->ensureChannel($schoolClass, $channel);
        $action->handle($channel, $request->validated());

        return redirect()->route('collaboration.channels.show', [$schoolClass, $channel])->with('success', 'Announcement draft created.');
    }

    public function edit(SchoolClass $schoolClass, Channel $channel, Announcement $announcement): Response
    {
        $this->ensureAnnouncement($schoolClass, $channel, $announcement);
        $this->authorize('update', $announcement);

        return Inertia::render('Collaboration/Announcements/Edit', ['schoolClass' => $schoolClass, 'channel' => $channel, 'announcement' => $announcement]);
    }

    public function update(UpdateAnnouncementRequest $request, SchoolClass $schoolClass, Channel $channel, Announcement $announcement, UpdateAnnouncement $action): RedirectResponse
    {
        $this->ensureAnnouncement($schoolClass, $channel, $announcement);
        $action->handle($announcement, $request->validated());

        return back()->with('success', 'Announcement updated.');
    }

    public function schedule(ScheduleAnnouncementRequest $request, SchoolClass $schoolClass, Channel $channel, Announcement $announcement, ScheduleAnnouncement $action): RedirectResponse
    {
        $this->ensureAnnouncement($schoolClass, $channel, $announcement);
        $action->handle($announcement, $request->validated('publish_at'));

        return redirect()->route('collaboration.channels.show', [$schoolClass, $channel])->with('success', 'Announcement scheduled.');
    }

    public function publish(PublishAnnouncementRequest $request, SchoolClass $schoolClass, Channel $channel, Announcement $announcement, PublishAnnouncement $action): RedirectResponse
    {
        $this->ensureAnnouncement($schoolClass, $channel, $announcement);
        $action->handle($announcement);

        return back()->with('success', 'Announcement published.');
    }

    public function archive(ArchiveAnnouncementRequest $request, SchoolClass $schoolClass, Channel $channel, Announcement $announcement, ArchiveAnnouncement $action): RedirectResponse
    {
        $this->ensureAnnouncement($schoolClass, $channel, $announcement);
        $action->handle($announcement);

        return back()->with('success', 'Announcement archived.');
    }

    public function restore(RestoreAnnouncementRequest $request, SchoolClass $schoolClass, Channel $channel, Announcement $announcement, RestoreAnnouncement $action): RedirectResponse
    {
        $this->ensureAnnouncement($schoolClass, $channel, $announcement);
        $action->handle($announcement);

        return back()->with('success', 'Announcement restored as a draft.');
    }

    private function ensureChannel(SchoolClass $class, Channel $channel): void
    {
        abort_unless($channel->school_class_id === $class->id, 404);
    }

    private function ensureAnnouncement(SchoolClass $class, Channel $channel, Announcement $announcement): void
    {
        $this->ensureChannel($class, $channel);
        abort_unless($announcement->channel_id === $channel->id, 404);
    }
}
