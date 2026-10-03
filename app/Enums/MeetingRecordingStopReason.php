<?php

namespace App\Enums;

/**
 * Why a recording stopped capturing.
 *
 * The reason is recorded once, by the same single-flight transition that
 * invoked the provider stop, so the channel card and the audit trail always
 * describe the same instant.
 */
enum MeetingRecordingStopReason: string
{
    /** A teacher pressed Stop recording. */
    case Manual = 'manual';

    /** The server-authoritative scheduled_stop_at was reached. */
    case DurationReached = 'duration_reached';

    /**
     * The teacher who started the recording explicitly left the meeting.
     *
     * This is only ever set from the application's explicit Leave path, never
     * from a participant_left webhook, because a browser refresh or a LiveKit
     * reconnect produces the same webhook without any leave intent.
     */
    case RecorderLeft = 'recorder_left';

    /** The host ended the meeting for everyone. */
    case MeetingEnded = 'meeting_ended';

    /**
     * The provider could not be asked to stop, or reported a failure.
     *
     * The recording is still closed and still published to the channel so the
     * card can explain itself; it just never becomes watchable.
     */
    case ProviderFailed = 'provider_failed';
}