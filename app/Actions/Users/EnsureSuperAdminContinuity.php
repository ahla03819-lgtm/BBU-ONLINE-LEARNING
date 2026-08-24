<?php

namespace App\Actions\Users;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class EnsureSuperAdminContinuity
{
    public function deleting(User $user): void
    {
        if ($this->isFinal($user)) {
            $this->fail();
        }
    }

    public function changingStatus(User $user, AccountStatus $status): void
    {
        if ($status !== AccountStatus::Active && $this->isFinal($user)) {
            $this->fail();
        }
    }

    public function changingRole(User $user, string $role): void
    {
        if ($role !== 'Super Admin' && $this->isFinal($user)) {
            $this->fail();
        }
    }

    private function isFinal(User $user): bool
    {
        return $user->isActive() && $user->hasRole('Super Admin') && User::role('Super Admin')->where('status', AccountStatus::Active->value)->whereKeyNot($user->getKey())->doesntExist();
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['user' => 'The final active Super Admin must be preserved.']);
    }
}
