<?php

namespace App\Http\Requests\Academics;

use App\Enums\AcademicYearStatus;
use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        $year = $this->route('academicYear');

        return $year ? $this->user()->can('update', $year) : $this->user()->can('create', AcademicYear::class);
    }

    public function rules(): array
    {
        $id = $this->route('academicYear')?->id;

        return ['name' => ['required', 'string', 'max:50', Rule::unique('academic_years')->ignore($id)], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'], 'status' => ['required', Rule::enum(AcademicYearStatus::class)]];
    }
}
