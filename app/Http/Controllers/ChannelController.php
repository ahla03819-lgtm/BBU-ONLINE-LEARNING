<?php

namespace App\Http\Controllers;

use App\Actions\Collaboration\ArchiveChannel;
use App\Actions\Collaboration\CreateChannel;
use App\Actions\Collaboration\RestoreChannel;
use App\Actions\Collaboration\UpdateChannel;
use App\Http\Requests\Collaboration\ArchiveChannelRequest;
use App\Http\Requests\Collaboration\CreateChannelRequest;
use App\Http\Requests\Collaboration\RestoreChannelRequest;
use App\Http\Requests\Collaboration\UpdateChannelRequest;
use App\Models\Channel;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;

class ChannelController extends Controller
{
    public function store(CreateChannelRequest $request, SchoolClass $schoolClass, CreateChannel $action): RedirectResponse
    {
        $channel = $action->handle($schoolClass, $request->validated());

        return redirect()->route('collaboration.channels.show', [$schoolClass, $channel])->with('success', 'Channel created.');
    }

    public function update(UpdateChannelRequest $request, SchoolClass $schoolClass, Channel $channel, UpdateChannel $action): RedirectResponse
    {
        $this->ensureNested($schoolClass, $channel);
        $action->handle($channel, $request->validated());

        return back()->with('success', 'Channel updated.');
    }

    public function archive(ArchiveChannelRequest $request, SchoolClass $schoolClass, Channel $channel, ArchiveChannel $action): RedirectResponse
    {
        $this->ensureNested($schoolClass, $channel);
        $action->handle($channel);

        return redirect()->route('collaboration.classes.show', $schoolClass)->with('success', 'Channel archived.');
    }

    public function restore(RestoreChannelRequest $request, SchoolClass $schoolClass, Channel $channel, RestoreChannel $action): RedirectResponse
    {
        $this->ensureNested($schoolClass, $channel);
        $action->handle($channel);

        return back()->with('success', 'Channel restored.');
    }

    private function ensureNested(SchoolClass $class, Channel $channel): void
    {
        abort_unless($channel->school_class_id === $class->id, 404);
    }
}
