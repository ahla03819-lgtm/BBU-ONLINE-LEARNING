<?php

namespace App\Actions\Recordings;

use App\Models\MeetingRecording;
use App\Models\User;

class PurgeMeetingRecording
{
    public function handle(MeetingRecording $recording, User $actor): MeetingRecording
    {
        MeetingRecording::requireTrashSchema();

        abort(409, 'Permanent recording purge is temporarily unavailable while durable media-cleanup recovery is being designed.');
    }
}
