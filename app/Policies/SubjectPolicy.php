<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

class SubjectPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('subjects.view');
    }

    public function view(User $u, Subject $m): bool
    {
        return $u->can('subjects.view');
    }

    public function create(User $u): bool
    {
        return $u->can('subjects.create');
    }

    public function update(User $u, Subject $m): bool
    {
        return $u->can('subjects.update');
    }
}
