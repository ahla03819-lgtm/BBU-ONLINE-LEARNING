<?php

namespace App\Policies;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\ClassSubject;
use App\Models\User;
use App\Services\CourseworkAccess;

class AssignmentPolicy
{
    public function __construct(private CourseworkAccess $access) {}

    public function view(User $user, Assignment $assignment): bool
    {
        return $user->can('assignments.view') && $this->access->canViewAssignment($user, $assignment);
    }

    public function create(User $user, ClassSubject $subject): bool
    {
        return $user->can('assignments.create') && $this->access->canMutateAssignment($user, $subject);
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return $user->can('assignments.update') && in_array($assignment->status, [AssignmentStatus::Draft, AssignmentStatus::Published], true) && $this->access->canMutateAssignment($user, $assignment);
    }

    public function publish(User $user, Assignment $assignment): bool
    {
        return $user->can('assignments.publish') && $assignment->status === AssignmentStatus::Draft && $this->access->canMutateAssignment($user, $assignment);
    }

    public function close(User $user, Assignment $assignment): bool
    {
        return $user->can('assignments.close') && $assignment->status === AssignmentStatus::Published && $this->access->canMutateAssignment($user, $assignment);
    }

    public function archive(User $user, Assignment $assignment): bool
    {
        return $user->can('assignments.archive') && $assignment->status !== AssignmentStatus::Archived && ($this->access->isAdministrator($user) || $this->access->canMutateAssignment($user, $assignment));
    }

    public function restore(User $user, Assignment $assignment): bool
    {
        return $user->can('assignments.restore') && $assignment->status === AssignmentStatus::Archived && $this->access->isAdministrator($user);
    }
}
