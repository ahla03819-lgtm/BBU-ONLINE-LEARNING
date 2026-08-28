<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class VerifyNotificationPermissions extends Command
{
    protected $signature = 'notifications:verify-permissions';

    protected $description = 'Verify that Phase 9 notification permissions are synchronized';

    public function handle(): int
    {
        $permissions = ['notifications.view', 'notifications.mark-read'];
        $errors = [];

        foreach (['Super Admin', 'Admin', 'Teacher', 'Student'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            foreach ($permissions as $permission) {
                if (! Permission::query()->where('name', $permission)->exists() || ! $role?->hasPermissionTo($permission)) {
                    $errors[] = "$roleName: missing $permission";
                }
            }
        }

        if ($errors !== []) {
            $this->error('Notification RBAC is not synchronized. Run RolePermissionSeeder.');
            foreach ($errors as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        $this->info('Notification RBAC permissions are synchronized.');

        return self::SUCCESS;
    }
}
