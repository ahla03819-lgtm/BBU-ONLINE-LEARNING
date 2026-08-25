<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeacherClassAssignment> */
class TeacherClassAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return ['teacher_profile_id' => TeacherProfile::factory(), 'school_class_id' => SchoolClass::factory(), 'starts_on' => now()->toDateString(), 'ends_on' => null, 'current_slot' => 1];
    }
}
