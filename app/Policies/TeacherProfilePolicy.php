<?php

namespace App\Policies;

use App\Models\TeacherProfile;
use App\Models\User;

class TeacherProfilePolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('teachers.view');
    }

    public function view(User $u, TeacherProfile $p): bool
    {
        return $u->can('teachers.view') && (! $u->hasRole('Teacher') || $p->user_id === $u->id);
    }

    public function create(User $u): bool
    {
        return $u->can('teachers.create');
    }

    public function update(User $u, TeacherProfile $p): bool
    {
        return $u->can('teachers.update');
    }
}
