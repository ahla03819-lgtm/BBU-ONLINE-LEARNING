<?php

return [
    'default_max_participants' => (int) env('MEETING_DEFAULT_MAX_PARTICIPANTS', 50),
    'min_participants' => 2,
    'max_participants' => 500,
];
