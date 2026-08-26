<?php

namespace Tests\Feature\Phase6;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MeetingPermissionDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_fails_before_phase_five_permissions_are_synchronized(): void
    {
        $this->artisan('meetings:verify-permissions')->assertFailed();
    }

    public function test_pre_phase_five_roles_are_upgraded_idempotently_and_verify_successfully(): void
    {
        foreach (['Super Admin', 'Admin', 'Teacher', 'Student'] as $role) {
            Role::findOrCreate($role);
        }
        Permission::findOrCreate('meetings.host');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('meetings:verify-permissions')->assertSuccessful();
        foreach (['meetings.view', 'meetings.join', 'meetings.participants.view', 'meetings.tokens.issue'] as $permission) {
            $this->assertTrue(Role::findByName('Student')->hasPermissionTo($permission));
        }
    }
}
