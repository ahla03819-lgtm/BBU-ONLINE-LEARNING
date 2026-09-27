<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingJoinRequestStatus;
use App\Models\Meeting;
use App\Models\MeetingJoinRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RequestMeetingJoin
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, Meeting $meeting): MeetingJoinRequest
    {
        return DB::transaction(function () use ($actor, $meeting) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            Gate::forUser($actor)->authorize('join', $locked);
            $request = MeetingJoinRequest::query()->where('meeting_id', $locked->id)->where('requester_user_id', $actor->id)->lockForUpdate()->first();

            if ($request && (in_array($request->status, [MeetingJoinRequestStatus::Cancelled, MeetingJoinRequestStatus::Denied], true)
                || ($request->status === MeetingJoinRequestStatus::Admitted && ! $request->admitsCurrentEntry()))) {
                $request->update(['status' => MeetingJoinRequestStatus::Pending, 'requested_at' => now(), 'decided_at' => null, 'decided_by' => null]);
            } elseif (! $request) {
                $request = MeetingJoinRequest::query()->create(['meeting_id' => $locked->id, 'requester_user_id' => $actor->id, 'status' => MeetingJoinRequestStatus::Pending, 'requested_at' => now()]);
            }

            if ($request->wasRecentlyCreated || $request->status === MeetingJoinRequestStatus::Pending) {
                $this->audit->log('meeting.join-requested', $request, [], ['meeting_id' => $locked->id]);
            }

            return $request;
        });
    }
}
