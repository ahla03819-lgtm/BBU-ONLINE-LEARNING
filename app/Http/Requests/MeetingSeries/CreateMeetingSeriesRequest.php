<?php

namespace App\Http\Requests\MeetingSeries;

use App\Models\ClassSubject;
use App\Models\MeetingSeries;

class CreateMeetingSeriesRequest extends BaseMeetingSeriesRequest
{
    public function authorize(): bool
    {
        $class = $this->route('schoolClass');
        $subject = $this->input('class_subject_id')
            ? ClassSubject::query()->where('school_class_id', $class->id)->find($this->input('class_subject_id'))
            : null;

        return $this->user()->can('create', [MeetingSeries::class, $class, $subject]);
    }

    public function rules(): array
    {
        return $this->definitionRules();
    }
}
