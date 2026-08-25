<?php

namespace App\Http\Requests\People;

use Illuminate\Foundation\Http\FormRequest;

class EndEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('end', $this->route('enrollment'));
    }

    public function rules(): array
    {
        return ['ended_on' => ['required', 'date', 'after_or_equal:'.$this->route('enrollment')->enrolled_on->toDateString()], 'end_reason' => ['required', 'string', 'max:255']];
    }
}
