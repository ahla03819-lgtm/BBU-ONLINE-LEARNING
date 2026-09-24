<?php

namespace App\Http\Requests\MeetingSeries;

use App\Enums\MeetingRecurrenceType;
use App\Models\SchoolClass;
use App\Services\Meetings\RecurrenceExpander;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

abstract class BaseMeetingSeriesRequest extends FormRequest
{
    protected function definitionRules(bool $requiresVersion = false): array
    {
        $class = $this->route('schoolClass');
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'class_subject_id' => ['nullable', 'integer', Rule::exists('class_subjects', 'id')->where('school_class_id', $class->id)],
            'host_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'recurrence_type' => ['required', Rule::enum(MeetingRecurrenceType::class)],
            'weekdays' => ['exclude_unless:recurrence_type,selected_weekdays', 'required_if:recurrence_type,selected_weekdays', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct:strict'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'local_start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'between:'.config('calendar.min_duration_minutes').','.config('calendar.max_duration_minutes')],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'max_participants' => ['required', 'integer', 'between:'.config('meetings.min_participants').','.config('meetings.max_participants')],
        ];

        $rules['lifecycle_version'] = $requiresVersion
            ? ['required', 'integer', 'min:0']
            : ['prohibited'];

        foreach (['uuid', 'status', 'split_from_series_id', 'meeting_series_id', 'series_sync_version', 'series_override_at'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var SchoolClass $class */
            $class = $this->route('schoolClass');
            $class->loadMissing('academicYear');
            $startsOn = $this->date('starts_on');
            $endsOn = $this->date('ends_on');

            if ($startsOn->lt($class->academicYear->starts_on) || ($endsOn && $endsOn->gt($class->academicYear->ends_on))) {
                $validator->errors()->add('ends_on', 'The recurrence must stay within the class academic year.');

                return;
            }

            try {
                app(RecurrenceExpander::class)->expand($this->only([
                    'recurrence_type', 'weekdays', 'starts_on', 'ends_on', 'timezone',
                ]));
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }
}
