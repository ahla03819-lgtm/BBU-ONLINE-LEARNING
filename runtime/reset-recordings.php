<?php

/**
 * Clear meeting recordings and their channel cards so each runtime scenario starts
 * from a clean, known state. Verification only.
 */

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\MeetingRecording;
use App\Models\Message;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Message::query()->whereNotNull('meeting_recording_id')->delete();
MeetingRecording::query()->delete();

// Each scenario starts from a live meeting, so an End-meeting pass does not leave
// the next one joining a meeting that is already over.
foreach (Meeting::query()->whereIn('status', [MeetingStatus::Ending, MeetingStatus::Ended])->get() as $meeting) {
    $meeting->update([
        'status' => MeetingStatus::Active,
        'actual_end_at' => null,
        'last_provider_error' => null,
    ]);
}

echo "cleared\n";
