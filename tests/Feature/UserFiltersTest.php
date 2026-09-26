<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_status_filter_active_inactive_suspended(): void
    {
        $active = $this->user('Admin', ['status' => AccountStatus::Active]);
        $inactive = $this->user('Admin', ['status' => AccountStatus::Inactive]);
        $suspended = $this->user('Admin', ['status' => AccountStatus::Suspended]);
        $superAdmin = $this->user('Super Admin');

        $this->actingAs($superAdmin)
            ->get(route('users.index', [
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => $this->idsMatch($rows, [$active->id, $superAdmin->id]))
                ->where('filters.status', 'active'));

        $this->actingAs($superAdmin)
            ->get(route('users.index', [
                'status' => 'inactive',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => $this->idsMatch($rows, [$inactive->id]))
                ->where('filters.status', 'inactive'));

        $this->actingAs($superAdmin)
            ->get(route('users.index', [
                'status' => 'suspended',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => $this->idsMatch($rows, [$suspended->id]))
                ->where('filters.status', 'suspended'));
    }

    public function test_verified_filter_yes_no(): void
    {
        $verified = $this->user('Admin');
        $unverified = $this->user('Admin', ['email_verified_at' => null]);
        $superAdmin = $this->user('Super Admin');

        $this->actingAs($superAdmin)
            ->get(route('users.index', [
                'verified' => 'yes',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => $this->idsMatch($rows, [$verified->id, $superAdmin->id]))
                ->where('filters.verified', 'yes'));

        $this->actingAs($superAdmin)
            ->get(route('users.index', [
                'verified' => 'no',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => $this->idsMatch($rows, [$unverified->id]))
                ->where('filters.verified', 'no'));
    }

    public function test_approval_filter_approved_pending(): void
    {
        $approved = $this->user('Admin', ['approved_at' => now()]);
        $pending = $this->user('Admin', ['approved_at' => null]);
        $superAdmin = $this->user('Super Admin');

        $this->actingAs($superAdmin)
            ->get(route('users.index', [
                'approval' => 'approved',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => $this->idsMatch($rows, [$approved->id, $superAdmin->id]))
                ->where('filters.approval', 'approved'));

        $this->actingAs($superAdmin)
            ->get(route('users.index', [
                'approval' => 'pending',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => $this->idsMatch($rows, [$pending->id]))
                ->where('filters.approval', 'pending'));
    }

    public function test_password_status_filter_normal_change_required(): void
    {
        $normal = $this->user('Admin', ['must_change_password' => false]);
        $changeRequired = $this->user('Admin', ['must_change_password' => true]);
        $superAdmin = $this->user('Super Admin');

        $this->actingAs($superAdmin)
            ->get(route('users.index', [
                'password_status' => 'normal',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => $this->idsMatch($rows, [$normal->id, $superAdmin->id]))
                ->where('filters.password_status', 'normal'));

        $this->actingAs($superAdmin)
            ->get(route('users.index', [
                'password_status' => 'change-required',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => $this->idsMatch($rows, [$changeRequired->id]))
                ->where('filters.password_status', 'change-required'));
    }

    private function idsMatch(mixed $rows, array $expectedIds): bool
    {
        return collect($rows)
            ->pluck('id')
            ->sort()
            ->values()
            ->all()
            === collect($expectedIds)
                ->sort()
                ->values()
                ->all();
    }

    private function user(string $role, array $attributes = []): User
    {
        return tap(User::factory()->create($attributes), fn (User $user) => $user->assignRole($role));
    }
}
