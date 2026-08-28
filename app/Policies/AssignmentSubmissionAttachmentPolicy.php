<?php

namespace App\Policies;

use App\Enums\SubmissionStatus;
use App\Models\AssignmentSubmissionAttachment;
use App\Models\User;
use App\Services\CourseworkAccess;

class AssignmentSubmissionAttachmentPolicy
{
    public function __construct(private CourseworkAccess $access) {}

    public function view(User $user, AssignmentSubmissionAttachment $attachment): bool
    {
        return $user->can('submission-attachments.download') && $this->access->canViewSubmission($user, $attachment->revision->submission);
    }

    public function delete(User $user, AssignmentSubmissionAttachment $attachment): bool
    {
        $submission = $attachment->revision->submission;

        return $user->can('submission-attachments.upload') && $attachment->revision->status === SubmissionStatus::Draft && $submission->studentProfile->user_id === $user->id && $this->access->canSubmit($user, $submission->assignment);
    }
}
