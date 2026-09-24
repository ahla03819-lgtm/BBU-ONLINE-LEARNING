<?php

namespace App\Http\Requests\MeetingSeries;

class UpdateMeetingSeriesRequest extends BaseMeetingSeriesRequest
{
    public function authorize(): bool
    {
        return $this->route('meetingSeries')->school_class_id === $this->route('schoolClass')->id
            && $this->user()->can('update', $this->route('meetingSeries'));
    }

    public function rules(): array
    {
        return $this->definitionRules(true);
    }
}
