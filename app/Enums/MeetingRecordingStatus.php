<?php

namespace App\Enums;

/**
 * Lifecycle of one server-side meeting recording.
 *
 * A recording is deliberately not a boolean. Start and stop are both provider
 * round-trips and the file only becomes playable after the provider has finished
 * writing it, so "recording" has to describe at least: we asked the provider to
 * start, the provider is capturing, we asked the provider to stop, the provider
 * output is still being finalised, the file is watchable, or the attempt failed.
 */
enum MeetingRecordingStatus: string
{
    /** The row exists and the provider start request is in flight. */
    case Starting = 'starting';

    /** The provider confirmed it is capturing this room. */
    case Recording = 'recording';

    /**
     * A stop has been claimed for this recording.
     *
     * This is the single-flight latch: only the caller that moves a recording
     * into Stopping may invoke the provider stop, so a timer deadline, an
     * explicit leave and an end-meeting racing each other still produce exactly
     * one provider request.
     */
    case Stopping = 'stopping';

    /** The provider has stopped and the output is being collected into app storage. */
    case Processing = 'processing';

    /** The output is in app-owned storage and authorised members may watch it. */
    case Ready = 'ready';

    /** The attempt cannot produce a watchable file. Terminal and reported. */
    case Failed = 'failed';

    /** Starting or Recording: the capture may still be running. */
    public function isLive(): bool
    {
        return in_array($this, [self::Starting, self::Recording], true);
    }

    /** Stopping or Processing: the provider is already finished and only collection remains. */
    public function isSettling(): bool
    {
        return in_array($this, [self::Stopping, self::Processing], true);
    }

    /** Ready or Failed: nothing further will change without a new attempt. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Failed], true);
    }
}