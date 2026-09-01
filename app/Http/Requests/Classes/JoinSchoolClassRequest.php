<?php

namespace App\Http\Requests\Classes;

use App\Models\SchoolClass;
use Illuminate\Foundation\Http\FormRequest;

class JoinSchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('joinByCode', SchoolClass::class);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[A-Za-z0-9\s-]+$/'],
            'student_profile_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'school_class_id' => ['prohibited'],
        ];
    }
}
