<?php

/**
 * Block until a meeting's recording reaches a terminal state, or time out.
 *
 * The runtime harness runs the real queue worker in the background, so this simply
 * observes the database the way an operator would. Verification only.
 *
 * Usage: php runtime/wait-for.php recording-is-ready [seconds]
 */

use App\Models\Meeting;
use App\Models\MeetingRecording;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$goal = $argv[1] ?? 'recording-is-ready';
$seconds = (int) ($argv[2] ?? 120);

$meetingId = (int) Meeting::query()->orderByDesc('id')->value('id');
$terminal = ['ready', 'failed'];
$deadline = microtime(true) + $seconds;

while (microtime(true) < $deadline) {
    $status = MeetingRecording::query()->where('meeting_id', $meetingId)->orderByDesc('id')->value('status');
    $status = $status instanceof \BackedEnum ? $status->value : $status;
    if ($status && ($goal === 'recording-is-ready' ? in_array($status, $terminal, true) : $status === $goal)) {
        echo $status, PHP_EOL;
        exit(0);
    }
    usleep(500000);
}

echo 'timeout:', ($status ?: 'none'), PHP_EOL;
exit(1);
