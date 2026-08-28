<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssignmentSubmission> */
class AssignmentSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return ['assignment_id' => Assignment::factory(), 'student_profile_id' => StudentProfile::factory(), 'status' => 'draft', 'latest_revision_number' => 0, 'lock_version' => 0];
    }
}
