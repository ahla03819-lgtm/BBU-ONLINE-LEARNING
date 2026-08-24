<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\AuditLogger;

class UpdateUser
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $user, array $data): User
    {
        $before = $user->only('name', 'email');
        $user->update($data);
        $this->audit->log('user.updated', $user, $before, $user->only('name', 'email'));

        return $user;
    }
}
