<?php

namespace App\Http\Requests\Classes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManageSchoolClassJoinCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageJoinCode', $this->route('schoolClass'));
    }

    public function rules(): array
    {
        return ['action' => ['required', 'string', Rule::in(['regenerate', 'enable', 'disable'])]];
    }
}
