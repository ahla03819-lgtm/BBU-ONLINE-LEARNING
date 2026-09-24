<?php

namespace App\Services\Meetings;

use App\Enums\MeetingSeriesStatus;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MeetingAccess;
use Illuminate\Validation\ValidationException;

class MeetingSeriesDefinition
{
    public function __construct(private MeetingAccess $access) {}

    public function attributes(User $actor, SchoolClass $schoolClass, array $data): array
    {
        $subject = $this->subject($schoolClass, $data['class_subject_id'] ?? null);
        $host = $this->host($actor, $schoolClass, $subject, $data['host_user_id'] ?? null);

        return [
            'school_class_id' => $schoolClass->id,
            'class_subject_id' => $subject?->id,
            'created_by' => $actor->id,
            'host_user_id' => $host->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'recurrence_type' => $data['recurrence_type'],
            'weekdays' => $data['recurrence_type'] === 'selected_weekdays' ? array_values($data['weekdays']) : null,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'local_start_time' => $data['local_start_time'],
            'duration_minutes' => $data['duration_minutes'],
            'timezone' => $data['timezone'],
            'max_participants' => $data['max_participants'] ?? config('meetings.default_max_participants'),
            'status' => MeetingSeriesStatus::Active,
        ];
    }

    public function subject(SchoolClass $schoolClass, mixed $id): ?ClassSubject
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

    private function host(User $actor, SchoolClass $schoolClass, ?ClassSubject $subject, mixed $hostId): User
    {
        $host = $this->access->isAdministrator($actor)
            ? ($hostId ? User::query()->find($hostId) : null)
            : $actor;

        if (! $host || ! $this->access->isEligibleHostFor($host, $schoolClass, $subject)) {
            throw ValidationException::withMessages(['host_user_id' => 'The selected host is not eligible for this meeting series.']);
        }

        return $host;
    }
}
