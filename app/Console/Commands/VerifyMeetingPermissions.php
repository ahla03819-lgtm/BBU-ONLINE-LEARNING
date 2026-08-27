<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class VerifyMeetingPermissions extends Command
{
    protected $signature = 'meetings:verify-permissions';

    protected $description = 'Verify that Phase 5 meeting permissions are synchronized for deployment roles';

    public function handle(): int
    {
        $expected = [
            'Super Admin' => $this->allPermissions(),
            'Admin' => $this->allPermissions(),
            'Teacher' => $this->allPermissions(),
            'Student' => ['meetings.view', 'meetings.join', 'meetings.participants.view', 'meetings.tokens.issue'],
        ];
        $missing = [];

        foreach ($expected as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->first();
            foreach ($permissions as $permission) {
                if (! Permission::query()->where('name', $permission)->exists() || ! $role?->hasPermissionTo($permission)) {
                    $missing[] = $roleName.': '.$permission;
                }
            }
        }

        if ($missing !== []) {
            $this->error('Meeting RBAC is not synchronized. Run: php artisan db:seed --class=RolePermissionSeeder --force');
            foreach ($missing as $item) {
                $this->line(' - '.$item);
            }

            return self::FAILURE;
        }

        $this->info('Meeting RBAC permissions are synchronized.');

        return self::SUCCESS;
    }

    private function allPermissions(): array
    {
        return ['meetings.view', 'meetings.create', 'meetings.update', 'meetings.cancel', 'meetings.start', 'meetings.end', 'meetings.join', 'meetings.participants.view', 'meetings.participants.remove', 'meetings.tokens.issue', 'meetings.screen-share'];
    }
}
