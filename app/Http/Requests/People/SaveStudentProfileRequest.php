<?php

namespace App\Http\Requests\People;

use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $m = $this->route('studentProfile');

        return $m ? $this->user()->can('update', $m) : $this->user()->can('create', StudentProfile::class);
    }

    public function rules(): array
    {
        $id = $this->route('studentProfile')?->id;

        return ['user_id' => ['required', 'exists:users,id', Rule::unique('student_profiles')->ignore($id)], 'student_number' => ['required', 'string', 'max:50', Rule::unique('student_profiles')->ignore($id)], 'date_of_birth' => ['nullable', 'date', 'before:today'], 'guardian_name' => ['nullable', 'string', 'max:255'], 'guardian_phone' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:2000']];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (TeacherProfile::query()->where('user_id', $this->user_id)->exists()) {
                $validator->errors()->add('user_id', 'This user already has a teacher profile.');
            }
        }];
    }
}
