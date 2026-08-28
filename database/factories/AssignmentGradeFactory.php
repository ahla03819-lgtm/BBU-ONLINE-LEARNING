<?php

namespace Database\Factories;

use App\Models\AssignmentGrade;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssignmentGrade> */
class AssignmentGradeFactory extends Factory
{
    public function definition(): array
    {
        return ['assignment_submission_id' => AssignmentSubmission::factory(), 'assignment_submission_revision_id' => fn (array $attributes) => AssignmentSubmissionRevision::factory()->create(['assignment_submission_id' => $attributes['assignment_submission_id']])->id, 'revision_number' => 1, 'points_awarded' => 80, 'max_points_snapshot' => 100, 'feedback' => fake()->sentence(), 'graded_by' => User::factory()];
    }
}
