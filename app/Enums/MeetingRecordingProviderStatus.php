<?php

namespace App\Enums;

/**
 * What the provider reports about one egress.
 *
 * MeetingProviderState models whether a LiveKit room is alive and deliberately
 * has no room for an egress that the provider itself failed. That distinction
 * matters here: an aborted or failed egress will never produce a file, so it has
 * to be reportable as a failure rather than folded into "ended" or "unknown".
 */
enum MeetingRecordingProviderStatus: string
{
    case Starting = 'starting';
    case Active = 'active';
    case Ending = 'ending';
    case Complete = 'complete';

    /** The provider itself gave up on this egress. No output will ever arrive. */
    case Failed = 'failed';

    /** The provider could not be asked, which proves nothing either way. */
    case Unknown = 'unknown';

    /** The egress is starting or capturing, so it may still produce output. */
    public function isLive(): bool
    {
        return in_array($this, [self::Starting, self::Active], true);
    }

    /** The egress is finished and its output is final, whether good or bad. */
    public function isFinished(): bool
    {
        return in_array($this, [self::Complete, self::Failed], true);
    }
}