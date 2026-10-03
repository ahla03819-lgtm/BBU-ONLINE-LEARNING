<?php

return [
    /*
    |---------------------------------------------------------------------------
    | Provider boundary
    |---------------------------------------------------------------------------
    |
    | Egress runs as its own LiveKit service, addressed separately from the Room
    | Service. On LiveKit Cloud it is a separately enabled service on its own host,
    | which matters for output: see the output section below. When it is not
    | configured, every recording start fails closed with a clear reason instead of
    | silently pretending to record.
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
    | Storage
    |---------------------------------------------------------------------------
    |
    | `disk` is an ordinary Laravel filesystem disk and is the single source of
    | truth for where a finished recording lives and how it is served. The
    | application never assumes the provider shares its filesystem.
    |
    |   local  a private disk on this machine: correct for development, and for a
    |         self-hosted Egress running on the same host.
    |   s3     any S3-compatible object store: AWS S3, Cloudflare R2 or MinIO. The
    |         project's own `s3` disk already reads AWS_* plus an optional endpoint
    |         and path-style flag, so no vendor-specific disk is defined here.
    |
    | `output.driver` decides how the provider is told to write. The two must agree:
    | when the provider uploads to object storage, `disk` must be that same object
    | store, otherwise the application will look for a file the provider never put
    | where it expects.
    |
    */
    'disk' => env('RECORDING_DISK', 'local'),
    'path_prefix' => env('RECORDING_PREFIX', 'meeting-recordings'),
    'file_type' => 'mp4',
    'mime_type' => 'video/mp4',

    'output' => [
        /*
         * local  the provider writes a relative path onto a filesystem this
         *        application shares with it. Only valid for a co-located Egress.
         * s3     the provider uploads the finished object itself to S3-compatible
         *        storage. This is the LiveKit Cloud path and the only one that works
         *        when the Egress worker runs on someone else's machine.
         */
        'driver' => env('RECORDING_OUTPUT_DRIVER', 'local'),

        /*
         * An explicit promise that the Egress worker shares this machine's
         * filesystem, and an assertion that nobody is relying on that silently.
         *
         * It is an opt-in because it cannot be inferred: a self-hosted Egress may
         * legitimately be reachable at a different hostname on the same box, while
         * a hosted Egress is never co-located no matter what it is called. Left off,
         * the local driver is refused, because a provider writing a path this
         * application cannot read produces recordings that are accepted, sit in
         * Processing, and then time out instead of failing when they are started.
         */
        'shared_filesystem' => (bool) env('RECORDING_OUTPUT_SHARED_FILESYSTEM', false),

        's3' => [
            'bucket' => env('RECORDING_EGRESS_S3_BUCKET', env('AWS_BUCKET')),
            'region' => env('RECORDING_EGRESS_S3_REGION', env('AWS_DEFAULT_REGION')),
            // Set for Cloudflare R2 or MinIO; leave empty for AWS S3.
            'endpoint' => env('RECORDING_EGRESS_S3_ENDPOINT', env('AWS_ENDPOINT')),
            'force_path_style' => (bool) env('RECORDING_EGRESS_S3_PATH_STYLE', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            'key' => env('RECORDING_EGRESS_S3_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('RECORDING_EGRESS_S3_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'session_token' => env('RECORDING_EGRESS_S3_SESSION_TOKEN', env('AWS_SESSION_TOKEN')),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Playback
    |---------------------------------------------------------------------------
    |
    | Object stores that support pre-signed reads hand the browser a short-lived
    | URL minted per authorized request, so the bytes never traverse the
    | application. Disks without it (a local disk) are streamed by an authorised
    | controller instead. Either way no permanent URL is ever stored on the
    | recording or in a channel message.
    |
    */
    'playback_url_ttl' => (int) env('RECORDING_PLAYBACK_URL_TTL', 300),

    /*
    |---------------------------------------------------------------------------
    | Collection
    |---------------------------------------------------------------------------
    |
    | A provider publishes its file result slightly before the object is readable,
    | so collection is retried rather than trusting a file result on its own. This
    | is deliberately bounded: an output that never becomes readable becomes an
    | explicit failure so a card cannot sit on Processing forever.
    |
    */
    'collection_attempts' => (int) env('MEETING_RECORDING_COLLECTION_ATTEMPTS', 5),
    'collection_backoff' => [15, 45, 120, 300, 600],
    'collection_deadline_minutes' => (int) env('MEETING_RECORDING_COLLECTION_DEADLINE_MINUTES', 30),
];
