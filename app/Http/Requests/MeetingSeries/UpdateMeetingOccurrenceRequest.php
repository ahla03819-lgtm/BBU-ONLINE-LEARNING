<?php

namespace App\Http\Requests\MeetingSeries;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMeetingOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $series = $this->route('meetingSeries');
        $meeting = $this->route('meeting');

        return $series->school_class_id === $this->route('schoolClass')->id
            && $meeting->meeting_series_id === $series->id
            && $this->user()->can('update', $series)
            && $this->user()->can('update', $meeting);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'scheduled_start_at' => ['required', 'date', 'after:now'],
            'scheduled_end_at' => ['required', 'date', 'after:scheduled_start_at'],
            'max_participants' => ['required', 'integer', 'between:'.config('meetings.min_participants').','.config('meetings.max_participants')],
            'lifecycle_version' => ['required', 'integer', 'min:0'],
            'meeting_series_id' => ['prohibited'],
            'series_occurrence_on' => ['prohibited'],
            'uuid' => ['prohibited'],
            'livekit_room_name' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
