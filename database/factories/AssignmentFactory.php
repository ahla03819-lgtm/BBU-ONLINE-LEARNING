<?php

namespace Database\Factories;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\ClassSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assignment> */
class AssignmentFactory extends Factory
{
    public function definition(): array
    {
        return ['class_subject_id' => ClassSubject::factory(), 'created_by' => User::factory(), 'title' => fake()->sentence(4), 'instructions' => fake()->paragraph(), 'max_points' => 100, 'due_at' => now()->addWeek(), 'allow_resubmission' => true, 'status' => AssignmentStatus::Draft, 'lifecycle_version' => 0];
    }

    public function published(): static
    {
        return $this->state(['status' => AssignmentStatus::Published, 'published_at' => now()]);
    }
}
