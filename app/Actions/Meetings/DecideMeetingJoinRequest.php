<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingJoinRequestStatus;
use App\Models\MeetingJoinRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DecideMeetingJoinRequest
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, MeetingJoinRequest $request, MeetingJoinRequestStatus $decision): MeetingJoinRequest
    {
        return DB::transaction(function () use ($actor, $request, $decision) {
            $locked = MeetingJoinRequest::query()->with('meeting')->lockForUpdate()->findOrFail($request->id);
            Gate::forUser($actor)->authorize('manageJoinRequests', $locked->meeting);
            if ($locked->status !== MeetingJoinRequestStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'This join request has already been decided.']);
            }
            $locked->update(['status' => $decision, 'decided_at' => now(), 'decided_by' => $actor->id]);
            $this->audit->log('meeting.join-request-'.$decision->value, $locked, [], ['meeting_id' => $locked->meeting_id]);

            return $locked;
        });
    }
}
