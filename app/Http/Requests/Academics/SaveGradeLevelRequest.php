<?php

namespace App\Http\Requests\Academics;

use App\Models\GradeLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveGradeLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $m = $this->route('gradeLevel');

        return $m ? $this->user()->can('update', $m) : $this->user()->can('create', GradeLevel::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        $id = $this->route('gradeLevel')?->id;

        return ['name' => ['required', 'string', 'max:100', Rule::unique('grade_levels')->ignore($id)], 'sequence' => ['required', 'integer', 'min:1', 'max:999', Rule::unique('grade_levels')->ignore($id)], 'is_active' => ['required', 'boolean']];
    }
}
