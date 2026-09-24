<?php

namespace App\Actions\Meetings;

use App\Models\Meeting;
use App\Models\MeetingSeries;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelMeetingOccurrence
{
    public function __construct(private CancelMeeting $cancelMeeting) {}

    public function handle(User $actor, MeetingSeries $series, Meeting $meeting, int $lifecycleVersion): Meeting
    {
        Gate::forUser($actor)->authorize('cancel', $series);
        if ($meeting->meeting_series_id !== $series->id) {
            abort(404);
        }
        if ($meeting->lifecycle_version !== $lifecycleVersion) {
            throw ValidationException::withMessages(['lifecycle_version' => 'The meeting occurrence changed. Refresh and try again.']);
        }

        $cancelled = $this->cancelMeeting->handle($actor, $meeting);
        $cancelled->update(['series_override_at' => $cancelled->series_override_at ?? now()]);

        return $cancelled;
    }
}
