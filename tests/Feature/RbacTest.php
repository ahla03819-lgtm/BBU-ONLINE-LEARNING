<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_all_four_roles_are_seeded_with_least_privilege(): void
    {
        $this->assertDatabaseCount('roles', 4);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');
        $student = User::factory()->create();
        $student->assignRole('Student');
        $this->assertTrue($admin->can('users.create'));
        $this->assertFalse($admin->can('settings.manage'));
        $this->assertTrue($teacher->can('assignments.manage'));
        $this->assertFalse($teacher->can('users.create'));
        $this->assertTrue($student->can('chat.use'));
        $this->assertFalse($student->can('users.view'));
    }

    public function test_teacher_and_student_cannot_access_user_administration(): void
    {
        foreach (['Teacher', 'Student'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user)->get('/users')->assertForbidden();
        }
    }

    public function test_admin_can_create_user_but_cannot_assign_super_admin_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin)->post('/users', ['name' => 'New User', 'email' => 'new@bbu.edu.kh', 'status' => 'active', 'role' => 'Student'])->assertRedirect('/users');
        $target = User::whereEmail('new@bbu.edu.kh')->firstOrFail();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->patch("/users/{$target->id}/role", ['role' => 'Super Admin'])->assertForbidden();
    }
}
