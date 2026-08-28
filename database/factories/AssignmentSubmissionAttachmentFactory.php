<?php

namespace Database\Factories;

use App\Models\AssignmentSubmissionAttachment;
use App\Models\AssignmentSubmissionRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AssignmentSubmissionAttachment> */
class AssignmentSubmissionAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return ['assignment_submission_revision_id' => AssignmentSubmissionRevision::factory(), 'uploaded_by' => fn (array $attributes) => AssignmentSubmissionRevision::query()->findOrFail($attributes['assignment_submission_revision_id'])->submission->studentProfile->user_id, 'client_uuid' => Str::uuid(), 'disk' => 'local', 'path' => 'coursework-submissions/'.Str::uuid(), 'original_name' => 'answer.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'size_bytes' => 10, 'sha256' => hash('sha256', fake()->uuid()), 'position' => 0];
    }
}
