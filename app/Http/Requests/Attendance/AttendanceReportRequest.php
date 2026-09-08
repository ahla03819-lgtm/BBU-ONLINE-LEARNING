<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attendance.view');
    }

    public function rules(): array
    {
        return [
            'school_class_id' => ['nullable', 'integer'],
            'academic_year_id' => ['nullable', 'integer'],
            'student_profile_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(AttendanceStatus::class)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
