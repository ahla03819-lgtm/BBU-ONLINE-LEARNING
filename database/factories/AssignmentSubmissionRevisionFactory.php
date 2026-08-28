<?php

namespace Database\Factories;

use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AssignmentSubmissionRevision> */
class AssignmentSubmissionRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['assignment_submission_id' => AssignmentSubmission::factory(), 'revision_number' => 1, 'client_uuid' => Str::uuid(), 'authored_by' => fn (array $attributes) => AssignmentSubmission::query()->findOrFail($attributes['assignment_submission_id'])->studentProfile->user_id, 'status' => 'draft', 'draft_slot' => 1, 'body' => fake()->paragraph(), 'lock_version' => 0];
    }
}
