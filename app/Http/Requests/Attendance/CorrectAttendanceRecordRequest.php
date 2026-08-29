<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectAttendanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('correct', $this->route('record'));
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(AttendanceStatus::class)], 'reason' => ['nullable', 'string', 'max:1000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'], 'correction_reason' => ['required', 'string', 'min:3', 'max:1000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'], 'attendance_register_id' => ['prohibited'], 'student_profile_id' => ['prohibited'], 'enrollment_id' => ['prohibited'], 'corrected_by' => ['prohibited'], 'corrected_at' => ['prohibited']];
    }
}
