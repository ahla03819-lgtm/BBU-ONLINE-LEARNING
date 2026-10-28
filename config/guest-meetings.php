<?php

return [
    // Conservative development defaults. Product owners must approve production
    // lifetimes before Guest-C makes invitations available to end users.
    'invitation_ttl_minutes' => 7 * 24 * 60,
    'session_ttl_minutes' => 60,
    'cookie_name' => 'bbu_guest_meeting_session',
    // This short-path cookie makes a retried form submit idempotent without
    // widening the credential cookie used by an individual waiting-room route.
    'retry_cookie_name' => 'bbu_guest_meeting_retry',
    'poll_interval_ms' => 5000,
    // The database record is authoritative; queue jobs are only the prompt path.
    // The scheduled reconciliation command recovers missed dispatches/workers.
    'removal_max_attempts' => 5,
    'removal_backoff_seconds' => [15, 60, 300, 900],
    'removal_lease_seconds' => 120,
    'reconciliation_batch_size' => 50,
    'cookie_secure' => env('GUEST_MEETING_COOKIE_SECURE', env('APP_ENV') === 'production'),
    'cookie_same_site' => 'lax',
];
