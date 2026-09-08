<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->is($user) || $actor->can('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.create');
    }

    public function update(User $actor, User $user): bool
    {
        return ($actor->is($user) || $actor->can('users.update')) && (! $user->hasRole('Super Admin') || $actor->hasRole('Super Admin'));
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->can('users.delete') && (! $user->hasRole('Super Admin') || $actor->hasRole('Super Admin'));
    }

    public function assignRole(User $actor, User $user): bool
    {
        return $actor->can('users.assign-role') && (! $user->hasRole('Super Admin') || $actor->hasRole('Super Admin'));
    }

    public function approve(User $actor, User $user): bool
    {
        return $this->assignRole($actor, $user) && ($actor->hasRole('Super Admin') ? ! $user->hasRole('Super Admin') : $user->hasAnyRole(['Teacher', 'Student']));
    }

    public function resetPassword(User $actor, User $user): bool
    {
        return ! $actor->is($user) && $this->approve($actor, $user);
    }
}
