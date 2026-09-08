<?php

namespace App\Policies;

use App\Models\StudentProfile;
use App\Models\User;

class StudentProfilePolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('students.view');
    }

    public function view(User $u, StudentProfile $p): bool
    {
        if (! $u->can('students.view')) {
            return false;
        }if ($u->hasRole('Student')) {
            return $p->user_id === $u->id;
        }if (! $u->hasRole('Teacher')) {
            return true;
        }

        return $p->enrollments()->where('current_slot', 1)->whereHas('schoolClass', fn ($q) => $q->whereHas('teacherAssignments', fn ($a) => $a->where('current_slot', 1)->whereHas('teacherProfile', fn ($t) => $t->where('user_id', $u->id)))->orWhereHas('classSubjects.teacherAssignments', fn ($a) => $a->where('current_slot', 1)->whereHas('teacherProfile', fn ($t) => $t->where('user_id', $u->id))))->exists();
    }

    public function create(User $u): bool
    {
        return $u->can('students.create');
    }

    public function update(User $u, StudentProfile $p): bool
    {
        return $u->can('students.update');
    }

    public function enroll(User $u, StudentProfile $p): bool
    {
        return $u->can('students.enroll');
    }
}
