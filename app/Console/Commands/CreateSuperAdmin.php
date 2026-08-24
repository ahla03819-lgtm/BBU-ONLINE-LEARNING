<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdmin extends Command
{
    protected $signature = 'edway:create-super-admin';

    protected $description = 'Securely create an initial verified Super Admin using interactive input';

    public function handle(AuditLogger $audit): int
    {
        $data = ['name' => $this->ask('Name'), 'email' => mb_strtolower(trim($this->ask('Email'))), 'password' => $this->secret('Password')];
        Validator::make($data, ['name' => ['required', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', Password::defaults()]])->validate();
        $user = User::query()->create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'status' => AccountStatus::Active, 'email_verified_at' => now()]);
        $user->assignRole('Super Admin');
        $audit->log('user.initial-super-admin-created', $user, [], $user->only('name', 'email', 'status'));
        $this->info('Super Admin created.');

        return self::SUCCESS;
    }
}
