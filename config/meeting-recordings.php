<?php

return [
    /*
    |---------------------------------------------------------------------------
    | Provider boundary
    |---------------------------------------------------------------------------
    |
    | Egress runs as its own LiveKit service. It is addressed separately from the
    | Room Service and is frequently deployed on its own host, so it gets its own
    | URL and its own on/off switch. When it is not configured, every recording
    | start fails closed with a clear reason instead of silently pretending to
    | record.
    |
    */
    'egress_api_url' => env('LIVEKIT_EGRESS_API_URL'),
    'egress_enabled' => (bool) env('LIVEKIT_EGRESS_ENABLED', false),

    /*
    |---------------------------------------------------------------------------
    | Output
    |---------------------------------------------------------------------------
    |
    | The composite layout handed to the provider. LiveKit's built-in
    | "screen-share" layout promotes an active screen share to the primary area
    | and keeps the participant rail beside it, and falls back to the normal
    | participant layout when nobody is sharing, which is exactly the behaviour
    | this feature needs and needs no custom layout template.
    |
    */
    'layout' => env('MEETING_RECORDING_LAYOUT', 'screen-share'),

    /*
    |---------------------------------------------------------------------------
    | Duration bounds
    |---------------------------------------------------------------------------
    |
    | A browser-supplied duration is never trusted: it is validated against these
    | bounds server-side and the scheduled stop is computed from server time.
    |
    */
    'min_duration_minutes' => 1,
    'max_duration_minutes' => (int) env('MEETING_RECORDING_MAX_MINUTES', 240),

    /*
    |---------------------------------------------------------------------------
    | Collection
    |---------------------------------------------------------------------------
    |
    | The provider writes its output to the relative path we choose. That path is
    | read back into app-owned storage so playback is served by an authorised
    | controller instead of a provider URL. When the egress service lives on a
    | separate host the file is not reachable from the application disk; the
    | recording then stays in Processing and the reconciliation command retries
    | within the window below, which is deliberately bounded so a permanently
    | unreachable output becomes an explicit failure rather than an endless wait.
    |
    */
    'disk' => env('MEETING_RECORDING_DISK', 'local'),
    'path_prefix' => 'meeting-recordings',
    'file_type' => 'mp4',
    'mime_type' => 'video/mp4',

    'collection_attempts' => (int) env('MEETING_RECORDING_COLLECTION_ATTEMPTS', 5),
    'collection_backoff' => [15, 45, 120, 300, 600],
    'collection_deadline_minutes' => (int) env('MEETING_RECORDING_COLLECTION_DEADLINE_MINUTES', 30),
];