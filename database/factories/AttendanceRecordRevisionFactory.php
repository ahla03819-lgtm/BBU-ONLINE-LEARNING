<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceRecordRevision> */
class AttendanceRecordRevisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'attendance_record_id' => AttendanceRecord::factory(),
            'previous_status' => AttendanceStatus::Absent,
            'previous_reason' => 'Original absence',
            'new_status' => AttendanceStatus::Excused,
            'new_reason' => 'Verified medical reason',
            'corrected_by' => User::factory(),
            'correction_reason' => 'Attendance evidence received',
            'corrected_at' => now(),
        ];
    }
}
