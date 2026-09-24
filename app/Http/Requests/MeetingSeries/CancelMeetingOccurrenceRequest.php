<?php

namespace App\Http\Requests\MeetingSeries;

use Illuminate\Foundation\Http\FormRequest;

class CancelMeetingOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $series = $this->route('meetingSeries');
        $meeting = $this->route('meeting');

        return $series->school_class_id === $this->route('schoolClass')->id
            && $meeting->meeting_series_id === $series->id
            && $this->user()->can('cancel', $series)
            && $this->user()->can('cancel', $meeting);
    }

    public function rules(): array
    {
        return ['lifecycle_version' => ['required', 'integer', 'min:0']];
    }
}
