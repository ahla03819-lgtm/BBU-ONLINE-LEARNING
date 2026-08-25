<?php

namespace App\Policies;

use App\Models\GradeLevel;
use App\Models\User;

class GradeLevelPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('grade-levels.view');
    }

    public function view(User $u, GradeLevel $m): bool
    {
        return $u->can('grade-levels.view');
    }

    public function create(User $u): bool
    {
        return $u->can('grade-levels.create');
    }

    public function update(User $u, GradeLevel $m): bool
    {
        return $u->can('grade-levels.update');
    }
}
