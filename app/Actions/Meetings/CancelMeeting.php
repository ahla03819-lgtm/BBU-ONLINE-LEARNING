<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingScreenShareRequestStatus;
use App\Enums\MeetingStatus;
use App\Events\MeetingCancelled;
use App\Events\MeetingScreenShareRequestChanged;
use App\Models\Meeting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MeetingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelMeeting
{
    public function __construct(private AuditLogger $audit, private MeetingAccess $access) {}

    public function handle(User $actor, Meeting $meeting): Meeting
    {
        Gate::forUser($actor)->authorize('cancel', $meeting);

        return DB::transaction(function () use ($actor, $meeting) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            if ($locked->status === MeetingStatus::Cancelled) {
                return $locked;
            }
            if ($locked->status !== MeetingStatus::Scheduled) {
                throw ValidationException::withMessages(['meeting' => 'Only scheduled meetings may be cancelled.']);
            }

            $before = $locked->only('status', 'lifecycle_version');
            $locked->update(['status' => MeetingStatus::Cancelled, 'lifecycle_version' => $locked->lifecycle_version + 1]);
            $locked->screenShareRequests()->whereNotNull('active_slot')->get()->each(function ($request) {
                $request->update(['status' => MeetingScreenShareRequestStatus::Cancelled, 'active_slot' => null, 'completed_at' => now()]);
                MeetingScreenShareRequestChanged::dispatch($request);
            });
            $this->audit->log('meeting.cancelled', $locked, $before, $locked->only('status', 'lifecycle_version'));
            if ($this->access->isSuperAdministrator($actor)) {
                $this->audit->log('meeting.super-admin-override', $locked, [], ['operation' => 'cancel']);
            }
            MeetingCancelled::dispatch($locked);

            return $locked;
        });
    }
}
