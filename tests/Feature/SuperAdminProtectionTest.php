<?php

namespace Tests\Feature;

use App\Actions\Users\AssignRoleToUser;
use App\Actions\Users\ChangeUserStatus;
use App\Actions\Users\EnsureSuperAdminContinuity;
use App\Enums\AccountStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SuperAdminProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function super(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        return $user;
    }

    public function test_final_active_super_admin_cannot_be_suspended_or_demoted_or_deleted(): void
    {
        $user = $this->super();
        foreach ([fn () => app(ChangeUserStatus::class)->handle($user, AccountStatus::Suspended), fn () => app(AssignRoleToUser::class)->handle($user, 'Admin'), fn () => app(EnsureSuperAdminContinuity::class)->deleting($user)] as $operation) {
            try {
                $operation();
                $this->fail('Invariant did not reject operation.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_changes_are_safe_when_another_active_super_admin_exists(): void
    {
        $first = $this->super();
        $this->super();
        app(ChangeUserStatus::class)->handle($first, AccountStatus::Suspended);
        $this->assertSame(AccountStatus::Suspended, $first->fresh()->status);
    }
}
