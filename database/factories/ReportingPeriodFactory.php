<?php

namespace Database\Factories;

use App\Enums\ReportingPeriodStatus;
use App\Models\AcademicYear;
use App\Models\ReportingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportingPeriod> */
class ReportingPeriodFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 months', '+2 months');

        return ['academic_year_id' => AcademicYear::factory(), 'parent_id' => null, 'name' => fake()->words(2, true), 'code' => fake()->unique()->bothify('period-###'), 'sequence' => 1, 'starts_on' => $start, 'ends_on' => (clone $start)->modify('+1 month'), 'status' => ReportingPeriodStatus::Draft];
    }
}
