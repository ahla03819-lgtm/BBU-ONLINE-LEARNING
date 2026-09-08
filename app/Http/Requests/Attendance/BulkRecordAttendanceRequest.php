<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkRecordAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('record', $this->route('attendanceRegister'));
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['mark_all_present', 'records'])],
            'records' => ['required_if:mode,records', 'array', 'min:1', 'max:500'],
            'records.*.id' => ['required_with:records', 'integer', 'distinct'],
            'records.*.status' => ['required_with:records', Rule::enum(AttendanceStatus::class)],
            'records.*.reason' => ['nullable', 'string', 'max:1000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
            'school_class_id' => ['prohibited'],
            'attendance_register_id' => ['prohibited'],
            'student_profile_id' => ['prohibited'],
            'enrollment_id' => ['prohibited'],
            'recorded_by' => ['prohibited'],
            'recorded_at' => ['prohibited'],
        ];
    }
}
