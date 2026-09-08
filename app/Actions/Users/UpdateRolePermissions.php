<?php

namespace App\Actions\Users;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UpdateRolePermissions
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Role $role, array $permissionNames): void
    {
        DB::transaction(function () use ($role, $permissionNames) {
            $before = $role->permissions()->pluck('name')->sort()->values()->all();
            $permissions = Permission::query()->whereIn('name', $permissionNames)->get();
            $current = $role->permissions()->pluck('name')->all();
            $role->givePermissionTo($permissions->whereIn('name', array_diff($permissionNames, $current)));
            $role->revokePermissionTo(array_diff($current, $permissionNames));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->audit->log('role.permissions-changed', $role, ['permission_count' => count($before)], ['role' => $role->name, 'permission_count' => $permissions->count()]);
        });
    }
}
