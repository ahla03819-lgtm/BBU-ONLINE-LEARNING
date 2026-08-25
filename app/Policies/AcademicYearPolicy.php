<?php

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\User;

class AcademicYearPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('academic-years.view');
    }

    public function view(User $u, AcademicYear $y): bool
    {
        return $u->can('academic-years.view');
    }

    public function create(User $u): bool
    {
        return $u->can('academic-years.create');
    }

    public function update(User $u, AcademicYear $y): bool
    {
        return $u->can('academic-years.update');
    }
}
