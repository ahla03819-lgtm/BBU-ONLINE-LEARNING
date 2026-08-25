<?php

namespace Database\Factories;

use App\Models\GradeLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GradeLevel> */
class GradeLevelFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->numerify('Grade ##'), 'sequence' => fake()->unique()->numberBetween(1, 999), 'is_active' => true];
    }
}
