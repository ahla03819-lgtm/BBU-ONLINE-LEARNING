<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveUser
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $user, User $actor): void
    {
        if (! str_ends_with(mb_strtolower($user->email), '@bbu.edu.kh')) {
            throw ValidationException::withMessages(['email' => 'Only institutional BBU accounts may be approved.']);
        }

        DB::transaction(function () use ($user, $actor) {
            $user->refresh();

            if ($user->approved_at) {
                return;
            }

            $approvedAt = now();
            $user->forceFill([
                'approved_at' => $approvedAt,
                'approved_by' => $actor->id,
                'email_verified_at' => $approvedAt,
            ])->save();

            $this->audit->log('user.approved', $user, [], []);
        });
    }
}
