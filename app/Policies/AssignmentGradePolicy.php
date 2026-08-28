<?php

namespace App\Policies;

use App\Models\AssignmentGrade;
use App\Models\AssignmentSubmission;
use App\Models\User;
use App\Services\CourseworkAccess;

class AssignmentGradePolicy
{
    public function __construct(private CourseworkAccess $access) {}

    public function view(User $user, AssignmentGrade $grade): bool
    {
        return (($grade->submission->studentProfile->user_id === $user->id && $user->can('grades.view-own')) || $user->can('submissions.review')) && $this->access->canViewSubmission($user, $grade->submission);
    }

    public function create(User $user, AssignmentSubmission $submission): bool
    {
        return $user->can('grades.create') && $this->access->canReview($user, $submission);
    }

    public function update(User $user, AssignmentSubmission $submission): bool
    {
        return $user->can('grades.update') && $this->access->canReview($user, $submission);
    }
}
