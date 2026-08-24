<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['users.view', 'users.create', 'users.update', 'users.delete', 'users.assign-role', 'students.view', 'students.manage', 'teachers.view', 'teachers.manage', 'classes.view', 'classes.create', 'classes.manage', 'channels.view', 'channels.manage', 'announcements.manage', 'chat.use', 'assignments.manage', 'attendance.manage', 'grades.manage', 'meetings.create', 'meetings.host', 'reports.view', 'audit.view', 'settings.manage'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }
        Role::findOrCreate('Super Admin')->syncPermissions($permissions);
        Role::findOrCreate('Admin')->syncPermissions(array_diff($permissions, ['users.delete', 'settings.manage']));
        Role::findOrCreate('Teacher')->syncPermissions(['students.view', 'classes.view', 'channels.view', 'chat.use', 'assignments.manage', 'attendance.manage', 'grades.manage', 'meetings.create', 'meetings.host']);
        Role::findOrCreate('Student')->syncPermissions(['classes.view', 'channels.view', 'chat.use']);
    }
}
