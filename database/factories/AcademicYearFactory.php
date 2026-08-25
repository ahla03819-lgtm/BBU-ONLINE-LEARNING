<?php

namespace Database\Factories;

use App\Enums\AcademicYearStatus;
use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicYear> */
class AcademicYearFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 years', '+2 years');

        return ['name' => fake()->unique()->numerify('20##/20##'), 'starts_on' => $start, 'ends_on' => (clone $start)->modify('+10 months'), 'status' => AcademicYearStatus::Planned, 'active_slot' => null];
    }

    public function active(): static
    {
        return $this->state(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);
    }
}
