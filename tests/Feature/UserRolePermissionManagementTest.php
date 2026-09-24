<?php

namespace Tests\Feature;

use App\Actions\Users\UpdateRolePermissions;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_deleting_a_user_removes_only_their_managed_avatar_directory_after_the_database_delete(): void
    {
        Storage::fake('public');
        $administrator = $this->user('Super Admin');
        $user = $this->user('Student');
        $avatarPath = $user->managedAvatarDirectory().'/avatar.png';
        Storage::disk('public')->put($avatarPath, 'avatar');

        $this->actingAs($administrator)->delete('/users/'.$user->id)->assertRedirect('/users');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        Storage::disk('public')->assertMissing($avatarPath);
    }

    public function test_failed_user_deletion_does_not_remove_the_avatar_directory(): void
    {
        Storage::fake('public');
        $user = $this->user('Super Admin');
        $avatarPath = $user->managedAvatarDirectory().'/avatar.png';
        Storage::disk('public')->put($avatarPath, 'avatar');

        $this->actingAs($user)->delete('/users/'.$user->id)->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        Storage::disk('public')->assertExists($avatarPath);
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }
}
