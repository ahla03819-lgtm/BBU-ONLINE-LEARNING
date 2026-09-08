<?php

namespace Tests\Feature;

use App\Actions\Users\CreateUser;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InstitutionalAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_provisioning_creates_a_pending_user_with_only_a_server_owned_temporary_password(): void
    {
        Notification::fake();
        $actor = $this->user('Super Admin');
        $this->actingAs($actor);

        $user = app(CreateUser::class)->handle([
            'name' => 'Pending Student',
            'email' => 'pending.student@bbu.edu.kh',
            'status' => 'active',
            'role' => 'Student',
            'password' => 'attacker-controlled-password',
        ]);

        $this->assertNull($user->approved_at);
        $this->assertNull($user->approved_by);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('123456789', $user->password));
        $this->assertFalse($user->hasRole('Admin'));
        $this->assertTrue($user->hasRole('Student'));
        $this->assertStringNotContainsString('123456789', AuditLog::query()->get()->toJson());
    }

    public function test_creation_requires_an_exact_institutional_domain(): void
    {
        $actor = $this->user('Super Admin');

        $this->actingAs($actor)->post('/users', [
            'name' => 'External User',
            'email' => 'external@example.com',
            'status' => 'active',
            'role' => 'Student',
        ])->assertSessionHasErrors('email');

        $this->actingAs($actor)->post('/users', [
            'name' => 'Deceptive User',
            'email' => 'deceptive@bbu.edu.kh.example.com',
            'status' => 'active',
            'role' => 'Student',
        ])->assertSessionHasErrors('email');
    }

    public function test_approval_is_server_owned_and_role_scoped(): void
    {
        $admin = $this->user('Admin', ['approved_at' => null]);
        $teacher = $this->user('Teacher', ['approved_at' => null]);
        $superAdmin = $this->user('Super Admin', ['approved_at' => null]);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/users/{$teacher->id}/approve", ['approved_by' => $superAdmin->id])
            ->assertSessionHas('success');

        $teacher->refresh();
        $this->assertNotNull($teacher->approved_at);
        $this->assertSame($admin->id, $teacher->approved_by);
        $this->assertNotNull($teacher->email_verified_at);
        $this->assertTrue($teacher->must_change_password === false);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/users/{$superAdmin->id}/approve")
            ->assertForbidden();
    }

    public function test_super_admin_and_admin_can_only_approve_their_permitted_targets(): void
    {
        $superAdmin = $this->user('Super Admin');
        $admin = $this->user('Admin', ['approved_at' => null]);
        $student = $this->user('Student', ['approved_at' => null]);
        $teacher = $this->user('Teacher', ['approved_at' => null]);

        $this->actingAs($superAdmin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/users/{$admin->id}/approve")
            ->assertSessionHas('success');
        $this->assertSame($superAdmin->id, $admin->fresh()->approved_by);

        $admin->refresh();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/users/{$student->id}/approve")
            ->assertSessionHas('success');
        $this->assertSame($admin->id, $student->fresh()->approved_by);

        foreach (['Teacher', 'Student'] as $role) {
            $this->actingAs($this->user($role))->withSession(['auth.password_confirmed_at' => time()])
                ->post("/users/{$teacher->id}/approve")
                ->assertForbidden();
        }
    }

    public function test_approval_verifies_an_unverified_institutional_account_without_clearing_password_change_requirement(): void
    {
        Notification::fake();
        $superAdmin = $this->user('Super Admin');
        $this->actingAs($superAdmin);
        $pending = app(CreateUser::class)->handle([
            'name' => 'Unverified Pending Student',
            'email' => 'unverified.pending@bbu.edu.kh',
            'status' => 'active',
            'role' => 'Student',
        ]);

        $this->assertNull($pending->email_verified_at);
        $this->actingAs($superAdmin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/users/{$pending->id}/approve")
            ->assertSessionHas('success');

        $pending->refresh();
        $this->assertNotNull($pending->approved_at);
        $this->assertSame($superAdmin->id, $pending->approved_by);
        $this->assertNotNull($pending->email_verified_at);
        $this->assertTrue($pending->must_change_password);

        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', ['email' => $pending->email, 'password' => '123456789'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect('/set-password');
    }

    public function test_pending_and_external_accounts_cannot_authenticate_but_approved_accounts_can(): void
    {
        $pending = $this->user('Student', ['approved_at' => null]);
        $this->post('/login', ['email' => $pending->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $external = $this->user('Student', ['email' => 'legacy@example.test']);
        $this->post('/login', ['email' => $external->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $approved = $this->user('Student');
        $this->post('/login', ['email' => $approved->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($approved);
    }

    public function test_password_change_requirement_blocks_application_routes_until_a_new_password_is_set(): void
    {
        $user = $this->user('Student', ['must_change_password' => true]);

        foreach (['/dashboard', '/classes', '/conversations', '/notifications', '/my-account', '/users', '/administration/roles'] as $path) {
            $this->actingAs($user)->get($path)->assertRedirect('/set-password');
        }

        $this->actingAs($user)->get('/set-password')->assertOk();
        $this->actingAs($user)->put('/set-password', ['password' => '123456789', 'password_confirmation' => '123456789'])->assertSessionHasErrors('password');
        $this->actingAs($user)->put('/set-password', ['password' => 'A-new-password-123!', 'password_confirmation' => 'A-new-password-123!'])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('A-new-password-123!', $user->password));
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_administrative_reset_is_scoped_and_does_not_persist_the_temporary_password(): void
    {
        $admin = $this->user('Admin');
        $teacher = $this->user('Teacher');
        $superAdmin = $this->user('Super Admin');
        $oldHash = $teacher->password;

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/users/{$teacher->id}/reset-password")
            ->assertSessionHas('success');

        $teacher->refresh();
        $this->assertTrue($teacher->must_change_password);
        $this->assertNotSame($oldHash, $teacher->password);
        $this->assertTrue(Hash::check('123456789', $teacher->password));
        $this->assertStringNotContainsString('123456789', AuditLog::query()->get()->toJson());

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/users/{$superAdmin->id}/reset-password")
            ->assertForbidden();
    }

    private function user(string $role, array $attributes = []): User
    {
        return tap(User::factory()->create($attributes), fn (User $user) => $user->assignRole($role));
    }
}
