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
        $permissions = ['users.view', 'users.create', 'users.update', 'users.delete', 'users.assign-role', 'academic-years.view', 'academic-years.create', 'academic-years.update', 'grade-levels.view', 'grade-levels.create', 'grade-levels.update', 'subjects.view', 'subjects.create', 'subjects.update', 'students.view', 'students.manage', 'students.create', 'students.update', 'students.enroll', 'students.transfer', 'students.end-enrollment', 'teachers.view', 'teachers.manage', 'teachers.create', 'teachers.update', 'teachers.assign-class', 'teachers.assign-subject', 'classes.view', 'classes.create', 'classes.manage', 'classes.update', 'classes.assign-subjects', 'channels.view', 'channels.create', 'channels.update', 'channels.archive', 'channels.restore', 'announcements.view', 'announcements.create', 'announcements.update', 'announcements.publish', 'announcements.archive', 'announcements.restore', 'channels.manage', 'announcements.manage', 'chat.use', 'assignments.manage', 'attendance.manage', 'grades.manage', 'meetings.create', 'meetings.host', 'reports.view', 'audit.view', 'settings.manage'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }
        Role::findOrCreate('Super Admin')->syncPermissions($permissions);
        Role::findOrCreate('Admin')->syncPermissions(array_diff($permissions, ['users.delete', 'settings.manage']));
        Role::findOrCreate('Teacher')->syncPermissions(['academic-years.view', 'grade-levels.view', 'subjects.view', 'students.view', 'teachers.view', 'classes.view', 'channels.view', 'channels.create', 'channels.update', 'channels.archive', 'channels.restore', 'announcements.view', 'announcements.create', 'announcements.update', 'announcements.publish', 'announcements.archive', 'chat.use', 'assignments.manage', 'attendance.manage', 'grades.manage', 'meetings.create', 'meetings.host']);
        Role::findOrCreate('Student')->syncPermissions(['academic-years.view', 'grade-levels.view', 'subjects.view', 'students.view', 'classes.view', 'channels.view', 'announcements.view', 'chat.use']);
    }
}
