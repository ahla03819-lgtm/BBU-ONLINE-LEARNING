<?php

namespace App\Actions\Users;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ChangeUserStatus
{
    public function __construct(private EnsureSuperAdminContinuity $guard, private AuditLogger $audit) {}

    public function handle(User $user, AccountStatus $status): void
    {
        DB::transaction(function () use ($user, $status) {
            $this->guard->changingStatus($user, $status);
            $before = $user->status->value;
            $user->update(['status' => $status]);
            $this->audit->log('user.status-changed', $user, ['status' => $before], ['status' => $status->value]);
        });
    }
}
