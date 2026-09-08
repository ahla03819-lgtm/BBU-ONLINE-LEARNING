<?php

namespace Tests\Feature\Phase15;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\AdminActivityPayload;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_view_sanitized_administrative_activity_without_private_audit_payloads(): void
    {
        $admin = $this->user('Admin');
        $actor = $this->user('Admin');
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => 'user.created',
            'target_type' => User::class,
            'target_id' => $actor->id,
            'before' => ['password' => 'not exposed'],
            'after' => ['email' => 'private@example.test', 'message_body' => 'not exposed'],
        ]);
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => 'message.edited',
            'after' => ['body' => 'Private conversation body'],
        ]);

        $this->actingAs($admin)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->has('adminActivity.data', 1)
            ->where('adminActivity.data.0.action', 'user.created')
            ->where('adminActivity.data.0.message', 'created a user account')
            ->where('adminActivity.data.0.resource', $actor->name)
            ->where('adminActivity.data.0.actor.name', $actor->name)
            ->where('adminActivity.data.0.href', '/users')
            ->missing('adminActivity.data.0.before')
            ->missing('adminActivity.data.0.after')
            ->missing('adminActivity.data.0.ip_address')
            ->missing('adminActivity.data.0.user_agent'));
    }

    public function test_super_admin_can_view_administrative_activity_but_not_another_users_private_activity(): void
    {
        $superAdmin = $this->user('Super Admin');
        $owner = $this->user('Student');
        UserNotification::factory()->create([
            'user_id' => $owner->id,
            'type' => 'conversation.direct-message',
            'context' => ['actor_name' => 'Private member'],
        ]);
        AuditLog::query()->create(['action' => 'attendance.register-finalized']);

        $this->actingAs($superAdmin)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->has('adminActivity.data', 1)
            ->has('notifications.data', 0));
    }

    public function test_teacher_and_student_do_not_receive_admin_activity_data(): void
    {
        AuditLog::query()->create(['action' => 'user.created']);

        foreach (['Teacher', 'Student'] as $role) {
            $this->actingAs($this->user($role))->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
                ->where('adminActivity', null));
        }
    }

    public function test_unmapped_safe_actions_use_a_generic_fallback_without_exposing_audit_data(): void
    {
        $admin = $this->user('Admin');
        $log = AuditLog::query()->create([
            'actor_id' => $admin->id,
            'action' => 'safe.unmapped',
            'before' => ['message' => 'Private audit data'],
            'after' => ['token' => 'Private audit data'],
        ]);

        $payload = AdminActivityPayload::make($log->load('actor'), $admin);

        $this->assertSame('updated administrative settings', $payload['message']);
        $this->assertSame('Administrative settings were updated', $payload['fallback_message']);
        $this->assertArrayNotHasKey('before', $payload);
        $this->assertArrayNotHasKey('after', $payload);
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }
}
