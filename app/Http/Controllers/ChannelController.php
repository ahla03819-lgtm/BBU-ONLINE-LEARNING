<?php

namespace App\Http\Controllers;

use App\Actions\Collaboration\ArchiveChannel;
use App\Actions\Collaboration\CreateChannel;
use App\Actions\Collaboration\RestoreChannel;
use App\Actions\Collaboration\UpdateChannel;
use App\Enums\ChannelType;
use App\Http\Requests\Collaboration\ArchiveChannelRequest;
use App\Http\Requests\Collaboration\CreateChannelRequest;
use App\Http\Requests\Collaboration\RestoreChannelRequest;
use App\Http\Requests\Collaboration\UpdateChannelRequest;
use App\Models\Channel;
use App\Models\SchoolClass;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChannelController extends Controller
{
    public function updateImage(\App\Http\Requests\Collaboration\UpdateChannelImageRequest $request, SchoolClass $schoolClass, Channel $channel, AuditLogger $audit): RedirectResponse
    {
        $this->ensureNested($schoolClass, $channel);
        $this->ensureImageManageable($channel);
        $old = $channel->image_path;
        $path = $request->file('image')->storeAs("channel-images/{$channel->id}", Str::uuid().'.'.$request->file('image')->extension(), 'public');
        try { $channel->update(['image_path' => $path]); } catch (\Throwable $e) { Storage::disk('public')->delete($path); throw $e; }
        if ($old && str_starts_with($old, "channel-images/{$channel->id}/")) Storage::disk('public')->delete($old);
        // Written only after the nested/domain guards pass and the file and row
        // are both in place, so a denial or a failed mutation leaves no success
        // event behind. Managed paths only - never bytes, credentials or headers.
        $audit->log('channel.image.uploaded', $channel,
            ['image_path' => $old],
            ['image_path' => $path]);
        return back()->with('success', 'Channel image updated.');
    }

    public function destroyImage(\Illuminate\Http\Request $request, SchoolClass $schoolClass, Channel $channel, AuditLogger $audit): RedirectResponse
    {
        $this->ensureNested($schoolClass, $channel); $this->authorize('update', $channel); $this->ensureImageManageable($channel);
        $old = $channel->image_path; $channel->update(['image_path' => null]);
        if ($old && str_starts_with($old, "channel-images/{$channel->id}/")) Storage::disk('public')->delete($old);
        // Reached only once the guards passed, the row is cleared and the managed
        // file has been removed.
        $audit->log('channel.image.removed', $channel,
            ['image_path' => $old],
            ['image_path' => null]);
        return back()->with('success', 'Channel image removed.');
    }
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

    /**
     * Channel images are a Custom-channel-only feature. This guard is enforced
     * independently of the Gate::before Super Admin shortcut, which bypasses
     * ChannelPolicy entirely and would otherwise let an administrator attach an
     * image to a General, Announcement or Subject channel.
     *
     * Must run before any file is written or deleted.
     */
    private function ensureImageManageable(Channel $channel): void
    {
        abort_unless($channel->type === ChannelType::Custom, 403);
    }
}
