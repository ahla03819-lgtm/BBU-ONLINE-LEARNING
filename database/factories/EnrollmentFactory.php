<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Enrollment> */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        $class = SchoolClass::factory()->create();

        return ['student_profile_id' => StudentProfile::factory(), 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class, 'enrolled_on' => now()->toDateString(), 'ended_on' => null, 'end_reason' => null, 'current_slot' => 1];
    }
}
