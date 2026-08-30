<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingJoinRequestStatus;
use App\Models\MeetingJoinRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelMeetingJoinRequest
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, MeetingJoinRequest $request): MeetingJoinRequest
    {
        return DB::transaction(function () use ($actor, $request) {
            $locked = MeetingJoinRequest::query()->with('meeting')->lockForUpdate()->findOrFail($request->id);
            abort_unless($locked->requester_user_id === $actor->id && $locked->meeting->status->value === 'active', 403);
            if (! in_array($locked->status, [MeetingJoinRequestStatus::Pending, MeetingJoinRequestStatus::Admitted], true)) {
                throw ValidationException::withMessages(['request' => 'This join request can no longer be cancelled.']);
            }
            $locked->update(['status' => MeetingJoinRequestStatus::Cancelled, 'decided_at' => now(), 'decided_by' => $actor->id]);
            $this->audit->log('meeting.join-request-cancelled', $locked, [], ['meeting_id' => $locked->meeting_id]);

            return $locked;
        });
    }
}
