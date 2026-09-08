<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;

class ResetUserPassword
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $user): void
    {
        $user->forceFill(['password' => Hash::make('123456789'), 'must_change_password' => true])->save();
        $this->audit->log('user.password-reset', $user, [], []);
    }
}
