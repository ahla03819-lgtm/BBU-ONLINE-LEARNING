<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class AssignRoleToUser
{
    public function __construct(private EnsureSuperAdminContinuity $guard, private AuditLogger $audit) {}

    public function handle(User $user, string $role): void
    {
        DB::transaction(function () use ($user, $role) {
            $before = $user->getRoleNames()->all();
            $this->guard->changingRole($user, $role);
            $user->syncRoles([$role]);
            $this->audit->log('user.roles-changed', $user, ['roles' => $before], ['roles' => [$role]]);
        });
    }
}
