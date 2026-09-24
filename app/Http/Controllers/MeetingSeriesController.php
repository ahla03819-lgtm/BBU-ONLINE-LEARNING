<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\CancelMeetingOccurrence;
use App\Actions\Meetings\CancelMeetingSeries;
use App\Actions\Meetings\CreateMeetingSeries;
use App\Actions\Meetings\SplitMeetingSeries;
use App\Actions\Meetings\UpdateMeetingOccurrence;
use App\Actions\Meetings\UpdateMeetingSeries;
use App\Http\Requests\MeetingSeries\CancelMeetingOccurrenceRequest;
use App\Http\Requests\MeetingSeries\CancelMeetingSeriesRequest;
use App\Http\Requests\MeetingSeries\CreateMeetingSeriesRequest;
use App\Http\Requests\MeetingSeries\SplitMeetingSeriesRequest;
use App\Http\Requests\MeetingSeries\UpdateMeetingOccurrenceRequest;
use App\Http\Requests\MeetingSeries\UpdateMeetingSeriesRequest;
use App\Models\Meeting;
use App\Models\MeetingSeries;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;

class MeetingSeriesController extends Controller
{
    public function store(CreateMeetingSeriesRequest $request, SchoolClass $schoolClass, CreateMeetingSeries $action): JsonResponse
    {
        $series = $action->handle($request->user(), $schoolClass, $request->validated());

        return response()->json(['series' => $this->seriesData($series)], 201);
    }

    public function update(UpdateMeetingSeriesRequest $request, SchoolClass $schoolClass, MeetingSeries $meetingSeries, UpdateMeetingSeries $action): JsonResponse
    {
        $this->ensureSeries($schoolClass, $meetingSeries);
        $series = $action->handle($request->user(), $meetingSeries, $request->validated());

        return response()->json(['series' => $this->seriesData($series)]);
    }

    public function split(SplitMeetingSeriesRequest $request, SchoolClass $schoolClass, MeetingSeries $meetingSeries, SplitMeetingSeries $action): JsonResponse
    {
        $this->ensureSeries($schoolClass, $meetingSeries);
        $series = $action->handle($request->user(), $meetingSeries, $request->validated());

        return response()->json(['series' => $this->seriesData($series)], 201);
    }

    public function updateOccurrence(UpdateMeetingOccurrenceRequest $request, SchoolClass $schoolClass, MeetingSeries $meetingSeries, Meeting $meeting, UpdateMeetingOccurrence $action): JsonResponse
    {
        $this->ensureOccurrence($schoolClass, $meetingSeries, $meeting);
        $occurrence = $action->handle($request->user(), $meetingSeries, $meeting, $request->validated());

        return response()->json(['meeting' => $this->meetingData($occurrence)]);
    }

    public function cancelOccurrence(CancelMeetingOccurrenceRequest $request, SchoolClass $schoolClass, MeetingSeries $meetingSeries, Meeting $meeting, CancelMeetingOccurrence $action): JsonResponse
    {
        $this->ensureOccurrence($schoolClass, $meetingSeries, $meeting);
        $occurrence = $action->handle(
            $request->user(),
            $meetingSeries,
            $meeting,
            (int) $request->validated('lifecycle_version'),
        );

        return response()->json(['meeting' => $this->meetingData($occurrence)]);
    }

    public function cancel(CancelMeetingSeriesRequest $request, SchoolClass $schoolClass, MeetingSeries $meetingSeries, CancelMeetingSeries $action): JsonResponse
    {
        $this->ensureSeries($schoolClass, $meetingSeries);
        $series = $action->handle($request->user(), $meetingSeries, $request->validated());

        return response()->json(['series' => $this->seriesData($series)]);
    }

    private function ensureSeries(SchoolClass $schoolClass, MeetingSeries $series): void
    {
        abort_unless($series->school_class_id === $schoolClass->id, 404);
    }

    private function ensureOccurrence(SchoolClass $schoolClass, MeetingSeries $series, Meeting $meeting): void
    {
        $this->ensureSeries($schoolClass, $series);
        abort_unless($meeting->school_class_id === $schoolClass->id && $meeting->meeting_series_id === $series->id, 404);
    }

    private function seriesData(MeetingSeries $series): array
    {
        return [
            'uuid' => $series->uuid,
            'school_class_id' => $series->school_class_id,
            'class_subject_id' => $series->class_subject_id,
            'host_user_id' => $series->host_user_id,
            'title' => $series->title,
            'description' => $series->description,
            'recurrence_type' => $series->recurrence_type->value,
            'weekdays' => $series->weekdays,
            'starts_on' => $series->starts_on->toDateString(),
            'ends_on' => $series->ends_on?->toDateString(),
            'local_start_time' => $series->local_start_time,
            'duration_minutes' => $series->duration_minutes,
            'timezone' => $series->timezone,
            'max_participants' => $series->max_participants,
            'status' => $series->status->value,
            'lifecycle_version' => $series->lifecycle_version,
            'split_from_series_id' => $series->split_from_series_id,
            'occurrences_count' => $series->meetings_count ?? $series->meetings()->count(),
        ];
    }

    private function meetingData(Meeting $meeting): array
    {
        return [
            'uuid' => $meeting->uuid,
            'title' => $meeting->title,
            'scheduled_start_at' => $meeting->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $meeting->scheduled_end_at?->toIso8601String(),
            'status' => $meeting->status->value,
            'lifecycle_version' => $meeting->lifecycle_version,
            'series_occurrence_on' => $meeting->series_occurrence_on?->toDateString(),
            'is_exception' => $meeting->series_override_at !== null,
        ];
    }
}
