<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateUser
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $role = $data['role'];
            unset($data['role']);
            $user = User::query()->create($data);
            $user->syncRoles([$role]);
            $this->audit->log('user.created', $user, [], $user->only('name', 'email', 'status'));
            $this->audit->log('user.role-assigned', $user, [], ['role' => $role]);
            $user->sendEmailVerificationNotification();

            return $user;
        });
    }
}
