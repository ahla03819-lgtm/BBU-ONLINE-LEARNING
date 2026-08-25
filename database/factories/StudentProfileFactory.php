<?php

namespace Database\Factories;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentProfile> */
class StudentProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'student_number' => fake()->unique()->bothify('S-#####'), 'date_of_birth' => fake()->dateTimeBetween('-18 years', '-5 years'), 'guardian_name' => fake()->name(), 'guardian_phone' => fake()->phoneNumber(), 'notes' => null];
    }
}
