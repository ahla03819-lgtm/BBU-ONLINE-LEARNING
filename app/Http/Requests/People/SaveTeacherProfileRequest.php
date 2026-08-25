<?php

namespace App\Http\Requests\People;

use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $m = $this->route('teacherProfile');

        return $m ? $this->user()->can('update', $m) : $this->user()->can('create', TeacherProfile::class);
    }

    public function rules(): array
    {
        $id = $this->route('teacherProfile')?->id;

        return ['user_id' => ['required', 'exists:users,id', Rule::unique('teacher_profiles')->ignore($id)], 'employee_number' => ['required', 'string', 'max:50', Rule::unique('teacher_profiles')->ignore($id)], 'phone' => ['nullable', 'string', 'max:30'], 'hired_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000']];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (StudentProfile::query()->where('user_id', $this->user_id)->exists()) {
                $validator->errors()->add('user_id', 'This user already has a student profile.');
            }
        }];
    }
}
