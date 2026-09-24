<?php

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Models\Assignment;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CalendarData
{
    public function __construct(private MeetingAccess $meetingAccess) {}

    public function for(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $events = collect();

        if ($user->can('meetings.view')) {
            $meetings = $this->meetingAccess->meetingsFor($user)
                ->whereIn('status', [
                    MeetingStatus::Scheduled->value,
                    MeetingStatus::Starting->value,
                    MeetingStatus::Active->value,
                    MeetingStatus::Ending->value,
                ])
                ->where('scheduled_start_at', '<', $end)
                ->where(function ($query) use ($start) {
                    $query->whereNull('scheduled_end_at')
                        ->where('scheduled_start_at', '>=', $start)
                        ->orWhere('scheduled_end_at', '>', $start);
                })
                ->with(['schoolClass:id,name,section', 'classSubject.subject:id,code,name'])
                ->get();

            $events->push(...$meetings->map(fn (Meeting $meeting) => [
                'id' => 'meeting:'.$meeting->uuid,
                'type' => 'meeting',
                'title' => $meeting->title,
                'starts_at' => $meeting->scheduled_start_at?->toIso8601String(),
                'ends_at' => $meeting->scheduled_end_at?->toIso8601String(),
                'status' => $meeting->status->value,
                'school_class' => $meeting->schoolClass->only('id', 'name', 'section'),
                'subject' => $meeting->classSubject?->subject?->only('code', 'name'),
                'url' => route('meetings.show', [$meeting->school_class_id, $meeting->uuid]),
                'series' => $meeting->meeting_series_id ? [
                    'id' => $meeting->meeting_series_id,
                    'occurrence_on' => $meeting->series_occurrence_on?->toDateString(),
                    'is_exception' => $meeting->series_override_at !== null,
                ] : null,
            ]));
        }

        if ($user->can('assignments.view')) {
            $assignments = Assignment::query()
                ->whereNotNull('due_at')
                ->where('due_at', '>=', $start)
                ->where('due_at', '<', $end)
                ->with(['classSubject.schoolClass:id,name,section', 'classSubject.subject:id,code,name'])
                ->get()
                ->filter(fn (Assignment $assignment) => $user->can('view', $assignment));

            $events->push(...$assignments->map(fn (Assignment $assignment) => [
                'id' => 'assignment:'.$assignment->id,
                'type' => 'assignment',
                'title' => $assignment->title,
                'starts_at' => $assignment->due_at?->toIso8601String(),
                'ends_at' => null,
                'status' => $assignment->status->value,
                'school_class' => $assignment->classSubject->schoolClass->only('id', 'name', 'section'),
                'subject' => $assignment->classSubject->subject->only('code', 'name'),
                'url' => route('coursework.assignments.show', [
                    $assignment->classSubject->school_class_id,
                    $assignment->class_subject_id,
                    $assignment->id,
                ]),
                'series' => null,
            ]));
        }

        return $events->sortBy('starts_at')->values();
    }
}
