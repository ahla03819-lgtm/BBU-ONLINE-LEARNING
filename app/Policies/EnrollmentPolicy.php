<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy
{
    public function end(User $u, Enrollment $e): bool
    {
        return $u->can('students.end-enrollment');
    }

    public function transfer(User $u, Enrollment $e): bool
    {
        return $u->can('students.transfer');
    }
}
