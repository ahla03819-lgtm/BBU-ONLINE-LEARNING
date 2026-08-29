<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceRegister;
use Illuminate\Foundation\Http\FormRequest;

class OpenAttendanceRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('record', [AttendanceRegister::class, $this->route('schoolClass')]);
    }

    public function rules(): array
    {
        return ['attendance_date' => ['required', 'date_format:Y-m-d'], 'school_class_id' => ['prohibited'], 'student_profile_id' => ['prohibited'], 'enrollment_id' => ['prohibited'], 'opened_by' => ['prohibited'], 'status' => ['prohibited']];
    }
}
