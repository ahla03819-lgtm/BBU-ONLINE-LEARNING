<?php

return [
    'url' => env('LIVEKIT_URL'),
    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),
    'token_ttl_seconds' => 300,
    'webhook_max_bytes' => 262144,
];
