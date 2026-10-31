<?php

namespace App\Actions\Recordings;

use App\Enums\MeetingRecordingStatus;
use App\Models\MeetingRecording;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class DeleteMeetingRecording
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function handle(MeetingRecording $recording, User $actor, ?string $reason = null): MeetingRecording
    {
        MeetingRecording::requireTrashSchema();

        return DB::transaction(function () use ($recording, $actor, $reason) {
            $locked = MeetingRecording::query()->lockForUpdate()->findOrFail($recording->id);
            if ($locked->trashed()) {
                return $locked;
            }

            $before = $locked->only('status');
            $locked->deleted_by = $actor->id;
            $locked->deletion_reason = $reason ? mb_substr(trim($reason), 0, 500) : null;
            $locked->save();
            $locked->delete();

            $this->audit->log('meeting.recording-deleted', $locked, $before, [
                'recording_id' => $locked->id,
                'deleted_by' => $actor->id,
                'meeting_id' => $locked->meeting_id,
            ]);

            return $locked->fresh();
        });
    }
}
