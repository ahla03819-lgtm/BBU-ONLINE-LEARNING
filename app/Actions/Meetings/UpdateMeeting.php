<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingStatus;
use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MeetingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateMeeting
{
    public function __construct(private MeetingAccess $access, private AuditLogger $audit) {}

    public function handle(User $actor, Meeting $meeting, array $data): Meeting
    {
        Gate::forUser($actor)->authorize('update', $meeting);

        return DB::transaction(function () use ($actor, $meeting, $data) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            if ($locked->status !== MeetingStatus::Scheduled) {
                throw ValidationException::withMessages(['meeting' => 'Only scheduled meetings may be updated.']);
            }

            $schoolClass = $locked->schoolClass;
            $classSubject = $this->subject($locked, $data['class_subject_id'] ?? null);
            if (! $this->access->canCreateMeeting($actor, $schoolClass, $classSubject)) {
                throw ValidationException::withMessages(['class_subject_id' => 'You cannot manage meetings in the selected subject scope.']);
            }
            $host = $this->host($actor, $schoolClass, $classSubject, $data['host_user_id'] ?? null);

            $before = $locked->only('class_subject_id', 'host_user_id', 'title', 'scheduled_start_at', 'scheduled_end_at', 'max_participants');
            $locked->update([
                'class_subject_id' => $classSubject?->id,
                'host_user_id' => $host->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'scheduled_start_at' => $data['scheduled_start_at'],
                'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
                'max_participants' => $data['max_participants'] ?? config('meetings.default_max_participants'),
            ]);
            $after = $locked->only('class_subject_id', 'host_user_id', 'title', 'scheduled_start_at', 'scheduled_end_at', 'max_participants');
            $this->audit->log('meeting.updated', $locked, $before, $after);
            if ($before['host_user_id'] !== $after['host_user_id']) {
                $this->audit->log('meeting.host-changed', $locked, ['host_user_id' => $before['host_user_id']], ['host_user_id' => $after['host_user_id']]);
            }
            if ($this->access->isSuperAdministrator($actor)) {
                $this->audit->log('meeting.super-admin-override', $locked, [], ['operation' => 'update', 'host_user_id' => $host->id]);
            }

            return $locked;
        });
    }

    private function subject(Meeting $meeting, mixed $id): ?ClassSubject
    {
        if (! $id) {
            return null;
        }

        $subject = ClassSubject::query()->find($id);
        if (! $subject || $subject->school_class_id !== $meeting->school_class_id) {
            throw ValidationException::withMessages(['class_subject_id' => 'The selected subject does not belong to this class.']);
        }

        return $subject;
    }

    private function host(User $actor, SchoolClass $schoolClass, ?ClassSubject $classSubject, mixed $hostId): User
    {
        $host = $this->access->isAdministrator($actor) ? ($hostId ? User::query()->find($hostId) : null) : $actor;
        if (! $host || ! $this->access->isEligibleHostFor($host, $schoolClass, $classSubject)) {
            throw ValidationException::withMessages(['host_user_id' => 'The selected host is not eligible for this meeting scope.']);
        }

        return $host;
    }
}
