<?php

namespace Database\Factories;

use App\Enums\AttendanceRegisterStatus;
use App\Models\AttendanceRegister;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceRegister> */
class AttendanceRegisterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'attendance_date' => now()->toDateString(),
            'status' => AttendanceRegisterStatus::Draft,
            'roster_snapshot_at' => null,
            'opened_by' => User::factory(),
            'finalized_by' => null,
            'finalized_at' => null,
        ];
    }

    public function finalized(): static
    {
        return $this->state(fn () => [
            'status' => AttendanceRegisterStatus::Finalized,
            'roster_snapshot_at' => now(),
            'finalized_by' => User::factory(),
            'finalized_at' => now(),
        ]);
    }
}
