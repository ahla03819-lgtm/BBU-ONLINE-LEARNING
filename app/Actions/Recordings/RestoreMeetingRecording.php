<?php

namespace App\Actions\Recordings;

use App\Models\MeetingRecording;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class RestoreMeetingRecording
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function handle(MeetingRecording $recording, User $actor): MeetingRecording
    {
        MeetingRecording::requireTrashSchema();

        return DB::transaction(function () use ($recording, $actor) {
            $locked = MeetingRecording::withTrashed()->lockForUpdate()->findOrFail($recording->id);
            if (! $locked->trashed()) {
                return $locked;
            }

            $locked->restore();

            $this->audit->log('meeting.recording-restored', $locked, [], [
                'recording_id' => $locked->id,
                'restored_by' => $actor->id,
                'meeting_id' => $locked->meeting_id,
            ]);

            return $locked->fresh();
        });
    }
}
