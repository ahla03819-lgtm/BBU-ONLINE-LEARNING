<?php

namespace App\Actions\Meetings;

use App\Models\MeetingSeries;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meetings\MeetingOccurrenceGenerator;
use App\Services\Meetings\MeetingSeriesDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateMeetingSeries
{
    public function __construct(
        private MeetingSeriesDefinition $definition,
        private MeetingOccurrenceGenerator $generator,
        private AuditLogger $audit,
    ) {}

    public function handle(User $actor, SchoolClass $schoolClass, array $data): MeetingSeries
    {
        $subject = $this->definition->subject($schoolClass, $data['class_subject_id'] ?? null);
        Gate::forUser($actor)->authorize('create', [MeetingSeries::class, $schoolClass, $subject]);

        return DB::transaction(function () use ($actor, $schoolClass, $data) {
            $series = MeetingSeries::query()->create([
                ...$this->definition->attributes($actor, $schoolClass, $data),
                'lifecycle_version' => 0,
            ]);
            $this->generator->generateLocked($series);
            $this->audit->log('meeting-series.created', $series, [], $series->only(
                'school_class_id', 'class_subject_id', 'host_user_id', 'title', 'recurrence_type',
                'weekdays', 'starts_on', 'ends_on', 'local_start_time', 'duration_minutes',
                'timezone', 'max_participants', 'status', 'lifecycle_version'
            ));

            return $series->loadCount('meetings');
        });
    }
}
