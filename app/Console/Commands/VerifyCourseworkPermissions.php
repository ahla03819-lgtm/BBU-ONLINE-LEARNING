<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class VerifyCourseworkPermissions extends Command
{
    protected $signature = 'coursework:verify-permissions';

    protected $description = 'Verify that Phase 8 coursework permissions are synchronized';

    public function handle(): int
    {
        $all = ['assignments.view', 'assignments.create', 'assignments.update', 'assignments.publish', 'assignments.close', 'assignments.archive', 'assignments.restore', 'submissions.view-own', 'submissions.create', 'submissions.update-own', 'submissions.submit', 'submissions.review', 'grades.view-own', 'grades.create', 'grades.update', 'submission-attachments.upload', 'submission-attachments.download'];
        $student = ['assignments.view', 'submissions.view-own', 'submissions.create', 'submissions.update-own', 'submissions.submit', 'grades.view-own', 'submission-attachments.upload', 'submission-attachments.download'];
        $expected = ['Super Admin' => $all, 'Admin' => $all, 'Teacher' => $all, 'Student' => $student];
        $errors = [];
        foreach ($expected as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->first();
            foreach ($permissions as $permission) {
                if (! Permission::query()->where('name', $permission)->exists() || ! $role?->hasPermissionTo($permission)) {
                    $errors[] = "$roleName: missing $permission";
                }
            }
        }
        $studentRole = Role::query()->where('name', 'Student')->first();
        foreach (array_diff($all, $student) as $permission) {
            if (Permission::query()->where('name', $permission)->exists() && $studentRole?->hasPermissionTo($permission)) {
                $errors[] = "Student: must not have $permission";
            }
        }
        if ($errors) {
            $this->error('Coursework RBAC is not synchronized. Run RolePermissionSeeder.');
            foreach ($errors as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }
        $this->info('Coursework RBAC permissions are synchronized.');

        return self::SUCCESS;
    }
}
