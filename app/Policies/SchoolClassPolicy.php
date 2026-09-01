<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class SchoolClassPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('classes.view');
    }

    public function view(User $u, SchoolClass $class): bool
    {
        if (! $u->can('classes.view')) {
            return false;
        }
        if ($u->hasRole('Super Admin')) {
            return true;
        }
        if ($u->hasRole('Student')) {
            return $class->enrollments()->where('current_slot', 1)
                ->whereHas('studentProfile', fn ($query) => $query->where('user_id', $u->id))
                ->exists();
        }
        if (! $u->hasRole('Teacher')) {
            return true;
        }

        return $class->teacherAssignments()->where('current_slot', 1)->whereHas('teacherProfile', fn ($q) => $q->where('user_id', $u->id))->exists() || $class->classSubjects()->whereHas('teacherAssignments', fn ($q) => $q->where('current_slot', 1)->whereHas('teacherProfile', fn ($t) => $t->where('user_id', $u->id)))->exists();
    }

    public function viewMembers(User $u, SchoolClass $class): bool
    {
        return $this->view($u, $class);
    }

    public function create(User $u): bool
    {
        return $u->can('classes.create');
    }

    public function joinByCode(User $u): bool
    {
        return $u->can('classes.join-by-code') && $u->hasRole('Student');
    }

    public function manageJoinCode(User $u, SchoolClass $class): bool
    {
        return $u->can('classes.manage-join-code') && $u->can('classes.manage');
    }

    public function update(User $u, SchoolClass $c): bool
    {
        return $u->can('classes.update');
    }

    public function assignSubjects(User $u, SchoolClass $c): bool
    {
        return $u->can('classes.assign-subjects');
    }

    public function assignTeacher(User $u, SchoolClass $c): bool
    {
        return $u->can('teachers.assign-class');
    }
}
