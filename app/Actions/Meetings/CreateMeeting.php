<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingJoinPolicy;
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

class CreateMeeting
{
    public function __construct(private MeetingAccess $access, private AuditLogger $audit) {}

    public function handle(User $creator, SchoolClass $schoolClass, array $data): Meeting
    {
        $classSubject = $this->subject($schoolClass, $data['class_subject_id'] ?? null);
        Gate::forUser($creator)->authorize('create', [Meeting::class, $schoolClass, $classSubject]);
        $host = $this->host($creator, $schoolClass, $classSubject, $data['host_user_id'] ?? null);

        return DB::transaction(function () use ($creator, $schoolClass, $classSubject, $host, $data) {
            $meeting = Meeting::query()->create([
                'school_class_id' => $schoolClass->id,
                'class_subject_id' => $classSubject?->id,
                'created_by' => $creator->id,
                'host_user_id' => $host->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'scheduled_start_at' => $data['scheduled_start_at'],
                'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
                'status' => MeetingStatus::Scheduled,
                'join_policy' => MeetingJoinPolicy::ActiveOnly,
                'max_participants' => $data['max_participants'] ?? config('meetings.default_max_participants'),
                'lifecycle_version' => 0,
            ]);

            $safe = $meeting->only('school_class_id', 'class_subject_id', 'created_by', 'host_user_id', 'title', 'scheduled_start_at', 'scheduled_end_at', 'status', 'max_participants');
            $this->audit->log('meeting.created', $meeting, [], $safe);
            $this->audit->log('meeting.host-assigned', $meeting, [], ['host_user_id' => $host->id]);
            if ($this->access->isSuperAdministrator($creator)) {
                $this->audit->log('meeting.super-admin-override', $meeting, [], ['operation' => 'create', 'host_user_id' => $host->id]);
            }

            return $meeting;
        });
    }

    private function subject(SchoolClass $schoolClass, mixed $id): ?ClassSubject
    {
        if (! $id) {
            return null;
        }

        $subject = ClassSubject::query()->find($id);
        if (! $subject || $subject->school_class_id !== $schoolClass->id) {
            throw ValidationException::withMessages(['class_subject_id' => 'The selected subject does not belong to this class.']);
        }

        return $subject;
    }

    private function host(User $creator, SchoolClass $schoolClass, ?ClassSubject $classSubject, mixed $hostId): User
    {
        if (! $this->access->isAdministrator($creator)) {
            $host = $creator;
        } else {
            $host = $hostId ? User::query()->find($hostId) : null;
            if (! $host) {
                throw ValidationException::withMessages(['host_user_id' => 'Select an eligible current teacher host.']);
            }
        }

        if (! $this->access->isEligibleHostFor($host, $schoolClass, $classSubject)) {
            throw ValidationException::withMessages(['host_user_id' => 'The selected host is not eligible for this meeting scope.']);
        }

        return $host;
    }
}
