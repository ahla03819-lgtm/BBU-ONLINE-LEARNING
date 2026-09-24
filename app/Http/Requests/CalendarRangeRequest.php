<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalendarRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('meetings.view') || $this->user()->can('assignments.view');
    }

    public function rules(): array
    {
        return [
            'view' => ['required', Rule::in(['month', 'week', 'agenda'])],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $days = CarbonImmutable::parse($this->input('start'))->diffInDays(CarbonImmutable::parse($this->input('end')));
            if ($days > config('calendar.max_range_days')) {
                $validator->errors()->add('end', 'Calendar ranges may not exceed '.config('calendar.max_range_days').' days.');
            }
        });
    }
}
