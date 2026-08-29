<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRegister;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceRecord> */
class AttendanceRecordFactory extends Factory
{
    public function definition(): array
    {
        $enrollment = Enrollment::factory()->create();

        return [
            'attendance_register_id' => AttendanceRegister::factory()->create(['school_class_id' => $enrollment->school_class_id]),
            'student_profile_id' => $enrollment->student_profile_id,
            'enrollment_id' => $enrollment,
            'status' => AttendanceStatus::Present,
            'reason' => null,
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
