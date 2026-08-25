<?php

namespace Database\Factories;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeacherProfile> */
class TeacherProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'employee_number' => fake()->unique()->bothify('T-####'), 'phone' => fake()->phoneNumber(), 'hired_on' => fake()->date(), 'notes' => null];
    }
}
