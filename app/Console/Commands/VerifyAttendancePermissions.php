<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class VerifyAttendancePermissions extends Command
{
    protected $signature = 'attendance:verify-permissions';

    protected $description = 'Verify that Phase 10 attendance permissions are synchronized';

    public function handle(): int
    {
        $grants = [
            'Super Admin' => ['attendance.view', 'attendance.view-own', 'attendance.record', 'attendance.finalize', 'attendance.correct'],
            'Admin' => ['attendance.view', 'attendance.view-own', 'attendance.record', 'attendance.finalize', 'attendance.correct'],
            'Teacher' => ['attendance.view', 'attendance.record', 'attendance.finalize', 'attendance.correct'],
            'Student' => ['attendance.view-own'],
        ];
        $errors = [];

        foreach ($grants as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->first();
            foreach (['attendance.view', 'attendance.view-own', 'attendance.record', 'attendance.finalize', 'attendance.correct'] as $permission) {
                $hasPermission = Permission::query()->where('name', $permission)->exists() && $role?->hasPermissionTo($permission);
                if (in_array($permission, $permissions, true) && ! $hasPermission) {
                    $errors[] = "$roleName: missing $permission";
                }
                if (! in_array($permission, $permissions, true) && $hasPermission) {
                    $errors[] = "$roleName: unexpected $permission";
                }
            }
        }

        if ($errors !== []) {
            $this->error('Attendance RBAC is not synchronized. Run RolePermissionSeeder.');
            foreach ($errors as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        $this->info('Attendance RBAC permissions are synchronized.');

        return self::SUCCESS;
    }
}
