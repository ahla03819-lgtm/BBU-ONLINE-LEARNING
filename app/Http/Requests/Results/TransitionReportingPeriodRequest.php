<?php

namespace App\Http\Requests\Results;

use App\Enums\ReportingPeriodStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionReportingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transition', $this->route('reportingPeriod'));
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(ReportingPeriodStatus::class)]];
    }
}
