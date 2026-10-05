<?php

return [
    'default_max_participants' => (int) env('MEETING_DEFAULT_MAX_PARTICIPANTS', 50),
    'min_participants' => 2,
    'max_participants' => 500,

    /*
    |---------------------------------------------------------------------------
    | Stale active recovery
    |---------------------------------------------------------------------------
    |
    | A meeting becomes Active only when the provider confirmed its room, and it
    | leaves Active when somebody ends it or when the provider reports the room
    | finished. A host who closes their laptop is in neither case: the room is
    | gone at the provider, but nothing this application watches will ever say so.
    | Without a backstop such a meeting stays Active forever, and because the
    | domain deliberately permits parallel Active meetings, one class can collect
    | an unbounded pile of them.
    |
    | So reconciliation may end a meeting the provider has already ended. That is
    | the only automatic end this application owns, and it is scoped twice on
    | purpose:
    |
    |   enabled  opt-in. Off by default, so nothing changes until it is asked for.
    |   cutoff   the instant the backstop starts believing rows. A meeting is only
    |            eligible once it actually started at or after this, which is what
    |            keeps a row that predates the deployment from being reinterpreted
    |            as a current stale meeting. Both must be set; either one missing
    |            disables the recovery, so a half-configured deployment fails
    |            closed instead of acting on rows it cannot reason about.
    |
    | Neither is a licence to rewrite history. Rows that predate the cutoff are
    | left exactly as they are, for a separate deliberate repair to handle against
    | audit evidence.
    |
    */
    'active_recovery' => [
        'enabled' => (bool) env('MEETING_ACTIVE_RECOVERY_ENABLED', false),
        'cutoff' => env('MEETING_ACTIVE_RECOVERY_CUTOFF'),
    ],
];
