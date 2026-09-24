<?php

namespace App\Http\Requests\MeetingSeries;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelMeetingSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('meetingSeries')->school_class_id === $this->route('schoolClass')->id
            && $this->user()->can('cancel', $this->route('meetingSeries'));
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(['entire', 'this_and_future'])],
            'cutoff_on' => ['required_if:scope,this_and_future', 'nullable', 'date_format:Y-m-d'],
            'lifecycle_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
