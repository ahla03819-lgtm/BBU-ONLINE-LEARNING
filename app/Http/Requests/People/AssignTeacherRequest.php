<?php

namespace App\Http\Requests\People;

use Illuminate\Foundation\Http\FormRequest;

class AssignTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('schoolClass') ?? $this->route('classSubject');

        return $this->user()->can('assignTeacher', $target);
    }

    public function rules(): array
    {
        return ['teacher_profile_id' => ['required', 'exists:teacher_profiles,id'], 'starts_on' => ['required', 'date']];
    }
}
