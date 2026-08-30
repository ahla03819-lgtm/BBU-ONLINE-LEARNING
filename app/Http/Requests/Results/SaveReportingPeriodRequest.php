<?php

namespace App\Http\Requests\Results;

use App\Models\ReportingPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveReportingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $period = $this->route('reportingPeriod');

        return $period ? $this->user()->can('update', $period) : $this->user()->can('create', ReportingPeriod::class);
    }

    public function rules(): array
    {
        $period = $this->route('reportingPeriod');

        return [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'parent_id' => ['nullable', 'integer', Rule::exists('reporting_periods', 'id'), Rule::notIn([$period?->id])],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:100', Rule::unique('reporting_periods')->where(fn ($query) => $query->where('academic_year_id', $this->integer('academic_year_id')))->ignore($period?->id)],
            'sequence' => ['required', 'integer', 'min:1', 'max:65535'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ];
    }
}
