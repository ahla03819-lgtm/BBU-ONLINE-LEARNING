<?php

namespace Tests\Feature;

use App\Actions\Users\UpdateRolePermissions;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserRolePermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_only_administrators_can_view_the_user_and_role_catalogs(): void
    {
        foreach (['Admin', 'Super Admin'] as $role) {
            $this->actingAs($this->user($role))->get('/users')->assertOk();
        }

        foreach (['Teacher', 'Student'] as $role) {
            $this->actingAs($this->user($role))->get('/users')->assertForbidden();
        }
    }

    public function test_only_super_admin_can_change_role_permissions_and_the_change_is_audited(): void
    {
        $admin = $this->user('Admin');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->put('/administration/roles/'.Role::findByName('Admin')->id.'/permissions', ['permissions' => []])->assertForbidden();

        $superAdmin = $this->user('Super Admin');
        $this->actingAs($superAdmin);
        app(UpdateRolePermissions::class)->handle(Role::findByName('Teacher'), ['notifications.view']);

        $this->assertTrue(Role::findByName('Teacher')->hasPermissionTo('notifications.view'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.permissions-changed', 'actor_id' => $superAdmin->id]);
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }
}
