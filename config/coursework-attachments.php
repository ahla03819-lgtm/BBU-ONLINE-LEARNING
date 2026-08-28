<?php

return [
    'disk' => env('COURSEWORK_ATTACHMENT_DISK', 'local'),
    'max_files' => 5,
    'max_file_kilobytes' => 10 * 1024,
    'max_combined_bytes' => 25 * 1024 * 1024,
    'max_filename_length' => 180,
    'orphan_minimum_age_hours' => (int) env('COURSEWORK_ATTACHMENT_ORPHAN_HOURS', 24),
];
