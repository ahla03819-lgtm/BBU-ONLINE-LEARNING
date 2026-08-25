<?php

namespace App\Http\Requests\Academics;

use App\Enums\SchoolClassStatus;
use App\Models\SchoolClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        $m = $this->route('schoolClass');

        return $m ? $this->user()->can('update', $m) : $this->user()->can('create', SchoolClass::class);
    }

    public function rules(): array
    {
        return ['academic_year_id' => ['required', 'exists:academic_years,id'], 'grade_level_id' => ['required', 'exists:grade_levels,id'], 'name' => ['required', 'string', 'max:100'], 'section' => ['nullable', 'string', 'max:30'], 'status' => ['required', Rule::enum(SchoolClassStatus::class)], 'capacity' => ['nullable', 'integer', 'min:1', 'max:10000']];
    }
}
