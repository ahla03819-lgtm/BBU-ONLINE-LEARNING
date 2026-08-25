<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subject> */
class SubjectFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('SUB-###'), 'name' => fake()->unique()->words(2, true), 'is_active' => true];
    }
}
