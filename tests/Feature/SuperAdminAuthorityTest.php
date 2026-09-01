<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SuperAdminAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_super_admin_passes_the_complete_current_and_future_application_permission_catalog(): void
    {
        $superAdmin = $this->userWithRole('Super Admin');
        $role = Role::findByName('Super Admin');
        $role->revokePermissionTo('classes.manage');
        $futurePermission = Permission::findOrCreate('system.future-administration');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($superAdmin->fresh()->getAllPermissions()->contains('name', 'classes.manage'));

        foreach (Permission::query()->pluck('name') as $permission) {
            $this->assertTrue($superAdmin->fresh()->can($permission), "Super Admin should authorize {$permission}.");
        }

        $this->actingAs($superAdmin)->get(route('dashboard'))->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('classes.manage')
                && collect($permissions)->contains($futurePermission->name)),
        );
    }

    public function test_seeder_adds_catalog_permissions_without_removing_a_manual_role_grant(): void
    {
        $manualPermission = Permission::findOrCreate('manual.teacher-grant');
        $teacher = Role::findByName('Teacher');
        $teacher->givePermissionTo($manualPermission);

        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue($teacher->fresh()->hasPermissionTo($manualPermission));
    }

    public function test_super_admin_cannot_read_or_change_another_users_notification_inbox(): void
    {
        $superAdmin = $this->userWithRole('Super Admin');
        $otherUser = User::factory()->create();
        $notification = UserNotification::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($superAdmin->can('view', $notification));
        $this->assertFalse($superAdmin->can('markRead', $notification));
    }

    public function test_admin_teacher_and_student_do_not_receive_super_admin_authority(): void
    {
        $admin = $this->userWithRole('Admin');
        $teacher = $this->userWithRole('Teacher');
        $student = $this->userWithRole('Student');

        $this->assertFalse($admin->can('users.delete'));
        $this->assertFalse($teacher->can('classes.manage'));
        $this->assertFalse($student->can('classes.manage'));
        $this->assertFalse($student->can('settings.manage'));
    }

    public function test_inactive_or_unverified_super_admin_does_not_receive_the_global_override(): void
    {
        $inactive = $this->userWithRole('Super Admin');
        $inactive->update(['status' => 'inactive']);
        $unverified = $this->userWithRole('Super Admin');
        $unverified->forceFill(['email_verified_at' => null])->save();

        $this->assertFalse($inactive->can('system.future-administration'));
        $this->assertFalse($unverified->can('system.future-administration'));
    }

    private function userWithRole(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }
}
