<?php

namespace App\Http\Requests\MeetingSeries;

class SplitMeetingSeriesRequest extends UpdateMeetingSeriesRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'cutoff_on' => ['required', 'date_format:Y-m-d', 'after:'.$this->route('meetingSeries')->starts_on->toDateString(), 'same:starts_on'],
        ];
    }
}
