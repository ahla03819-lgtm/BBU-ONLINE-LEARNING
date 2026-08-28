<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\User;
use App\Services\CourseworkAccess;

class AssignmentSubmissionPolicy
{
    public function __construct(private CourseworkAccess $access) {}

    public function view(User $user, AssignmentSubmission $submission): bool
    {
        return (($submission->studentProfile?->user_id === $user->id && $user->can('submissions.view-own')) || $user->can('submissions.review')) && $this->access->canViewSubmission($user, $submission);
    }

    public function create(User $user, Assignment $assignment): bool
    {
        return $user->can('submissions.create') && $this->access->canSubmit($user, $assignment);
    }

    public function update(User $user, AssignmentSubmission $submission): bool
    {
        return $user->can('submissions.update-own') && $submission->studentProfile->user_id === $user->id && $this->access->canSubmit($user, $submission->assignment);
    }

    public function submit(User $user, AssignmentSubmission $submission): bool
    {
        return $user->can('submissions.submit') && $submission->studentProfile->user_id === $user->id && $this->access->canSubmit($user, $submission->assignment);
    }

    public function review(User $user, AssignmentSubmission $submission): bool
    {
        return $user->can('submissions.review') && $this->access->canReview($user, $submission);
    }
}
