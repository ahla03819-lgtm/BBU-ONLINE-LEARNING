<?php

namespace Tests\Feature;

use App\Actions\Users\CreateUser;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_creation_and_role_assignment_are_audited_without_secrets(): void
    {
        Notification::fake();
        $actor = User::factory()->create();
        $actor->assignRole('Super Admin');
        $this->actingAs($actor);
        app(CreateUser::class)->handle(['name' => 'Audited', 'email' => 'audit@bbu.edu.kh', 'status' => 'active', 'role' => 'Student']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'actor_id' => $actor->id]);
        $json = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('Sensitive-Password-99', $json);
        $this->assertStringNotContainsString('password', $json);
    }
}
