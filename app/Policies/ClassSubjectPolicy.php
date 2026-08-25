<?php

namespace App\Policies;

use App\Models\ClassSubject;
use App\Models\User;

class ClassSubjectPolicy
{
    public function assignTeacher(User $u, ClassSubject $s): bool
    {
        return $u->can('teachers.assign-subject');
    }
}
