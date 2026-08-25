<?php

namespace App\Http\Requests\People;

use Illuminate\Foundation\Http\FormRequest;

class EnrollStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('enroll', $this->route('studentProfile'));
    }

    public function rules(): array
    {
        return ['school_class_id' => ['required', 'exists:school_classes,id'], 'enrolled_on' => ['required', 'date']];
    }
}
