<?php

namespace App\Support;

use App\Models\MeetingRecording;
use Illuminate\Support\Carbon;

/**
 * The one authoritative projection of a recording's state.
 *
 * Both the realtime broadcast and the polling fallback render from this, so every
 * participant computes the same countdown from the same stored deadline. A browser
 * is handed server_now_at alongside the timestamps so its remaining time is derived
 * from server time instead of from whatever its own clock believes, and a refresh
 * resumes from the stored deadline rather than restarting.
 *
 * Nothing here is a file reference. The only URL is a route to an endpoint that
 * re-authorizes the viewer, and it is only present once the recording is Ready.
 */
class MeetingRecordingProjection
{
    public static function make(?MeetingRecording $recording, ?Carbon $serverNow = null): ?array
    {
        if (! $recording) {
            return null;
        }

        $recording->loadMissing(['meeting:id,uuid,school_class_id', 'starter:id,name']);
        $now = $serverNow ?? now();

        return [
            'reference' => $recording->public_uuid,
            'status' => $recording->status->value,
            'stop_reason' => $recording->stop_reason?->value,
            'started_at' => $recording->started_at?->toIso8601String(),
            // Null means "until manually stopped", which is what the control renders
            // as an open-ended recording rather than a countdown.
            'scheduled_stop_at' => $recording->scheduled_stop_at?->toIso8601String(),
            'stopped_at' => $recording->stopped_at?->toIso8601String(),
            'ready_at' => $recording->ready_at?->toIso8601String(),
            'duration_seconds' => $recording->duration_seconds,
            'size_bytes' => $recording->size_bytes,
            'layout' => $recording->layout,
            'recorded_by' => $recording->starter ? ['id' => $recording->starter->id, 'name' => $recording->starter->name] : null,
            'meeting' => $recording->meeting ? [
                'uuid' => $recording->meeting->uuid,
                'title' => $recording->meeting->title ?? null,
                'school_class_id' => $recording->meeting->school_class_id,
            ] : null,
            'can_start' => false,
            'can_stop' => false,
            'failure_reason' => $recording->failure_reason,
            'playback_url' => $recording->isWatchable() && $recording->meeting
                ? route('meetings.recordings.play', [$recording->meeting->school_class_id, $recording->meeting->uuid, $recording])
                : null,
            'server_now_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Add the viewer's own control permissions to an already-built projection.
     */
    public static function withPermissions(array $projection, bool $canStart, bool $canStop): array
    {
        $projection['can_start'] = $canStart;
        $projection['can_stop'] = $canStop;

        return $projection;
    }
}
