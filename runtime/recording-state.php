<?php

/**
 * Runtime state reader: reports what the database actually holds for a meeting's
 * recording and its class-channel card. Used only by the runtime verification.
 *
 * Usage: php runtime/recording-state.php card
 *        php runtime/recording-state.php meeting
 */

use App\Models\Meeting;
use App\Models\MeetingRecording;
use App\Models\Message;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$what = $argv[1] ?? 'card';
$meetingId = (int) ($argv[2] ?? 0);

if (! $meetingId) {
    $meetingId = (int) Meeting::query()->orderByDesc('id')->value('id');
}

$recording = MeetingRecording::query()->where('meeting_id', $meetingId)->orderByDesc('id')->first();

if ($what === 'meeting') {
    $status = Meeting::query()->whereKey($meetingId)->value('status');
    echo $status instanceof \BackedEnum ? $status->value : $status, PHP_EOL;

    return;
}

$cards = $recording
    ? Message::query()->where('meeting_recording_id', $recording->id)->get()
    : collect();

echo json_encode([
    'recording_id' => $recording?->id,
    'count' => $cards->count(),
    'status' => $recording?->status?->value,
    'stop_reason' => $recording?->stop_reason?->value,
    'active_slot' => $recording?->active_slot,
    'started_at' => $recording?->started_at?->toIso8601String(),
    'scheduled_stop_at' => $recording?->scheduled_stop_at?->toIso8601String(),
    'stopped_at' => $recording?->stopped_at?->toIso8601String(),
    'ready_at' => $recording?->ready_at?->toIso8601String(),
    'duration_seconds' => $recording?->duration_seconds,
    'storage_disk' => $recording?->storage_disk,
    'storage_path' => $recording?->storage_path,
    'card_channel_id' => $cards->first()?->channel_id,
    'card_type' => $cards->first()?->type?->value,
    'card_sender_id' => $cards->first()?->sender_id,
    'card_client_uuid' => $cards->first()?->client_uuid,
], JSON_PRETTY_PRINT), PHP_EOL;
