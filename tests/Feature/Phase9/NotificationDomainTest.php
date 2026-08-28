<?php

namespace Tests\Feature\Phase9;

use App\Actions\Notifications\MarkAllUserNotificationsRead;
use App\Actions\Notifications\MarkUserNotificationRead;
use App\Actions\Notifications\StoreUserNotification;
use App\Enums\AccountStatus;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NotificationDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_notification_storage_is_idempotent_and_preserves_server_owned_context(): void
    {
        $recipient = $this->student();
        $actor = $this->teacher();
        $subject = User::factory()->create();
        $action = app(StoreUserNotification::class);

        $first = $action->handle(
            recipient: $recipient,
            type: 'assignment.published',
            deduplicationKey: 'assignment:42:published',
            context: ['title' => 'Research essay', 'class_id' => 7],
            actor: $actor,
            subject: $subject,
            routeName: 'dashboard',
            routeParameters: ['section' => 'coursework'],
            payloadVersion: 2,
        );
        $second = $action->handle(
            recipient: $recipient,
            type: 'assignment.published',
            deduplicationKey: 'assignment:42:published',
            context: ['title' => 'Changed by retry'],
            actor: $actor,
            subject: $subject,
            routeName: 'dashboard',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertSame('Research essay', $second->context['title']);
        $this->assertSame(2, $second->payload_version);
        $this->assertSame($actor->id, $second->actor_id);
        $this->assertSame($subject->getMorphClass(), $second->subject_type);
        $this->assertNotEmpty($second->public_id);
        $this->assertSame('public_id', $second->getRouteKeyName());
    }

    public function test_deduplication_key_cannot_be_reused_for_a_different_event(): void
    {
        $recipient = $this->student();
        $action = app(StoreUserNotification::class);
        $action->handle($recipient, 'assignment.published', 'event:one');

        $this->expectException(LogicException::class);
        $action->handle($recipient, 'assignment.graded', 'event:one');
    }

    public function test_database_enforces_recipient_and_deduplication_uniqueness(): void
    {
        $recipient = $this->student();
        UserNotification::factory()->create(['user_id' => $recipient, 'deduplication_key' => 'same-event']);

        $this->expectException(QueryException::class);
        UserNotification::factory()->create(['user_id' => $recipient, 'deduplication_key' => 'same-event']);
    }

    public function test_same_deduplication_key_is_valid_for_different_recipients(): void
    {
        UserNotification::factory()->create(['user_id' => $this->student(), 'deduplication_key' => 'shared-event']);
        UserNotification::factory()->create(['user_id' => $this->student(), 'deduplication_key' => 'shared-event']);

        $this->assertDatabaseCount('user_notifications', 2);
    }

    public function test_storage_rejects_ineligible_recipients_and_unsafe_payloads(): void
    {
        $action = app(StoreUserNotification::class);
        $inactive = User::factory()->create(['status' => AccountStatus::Inactive]);

        try {
            $action->handle($inactive, 'assignment.published', 'inactive');
            $this->fail('Inactive recipients must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('user_notifications', 0);
        }

        foreach ([
            ['context' => ['access_token' => 'private']],
            ['context' => ['external_url' => 'https://example.test/private']],
            ['routeName' => 'missing.external.route'],
        ] as $unsafe) {
            try {
                $action->handle($this->student(), 'assignment.published', fake()->uuid(), ...$unsafe);
                $this->fail('Unsafe notification payloads must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_policy_is_recipient_scoped_even_for_super_admin(): void
    {
        $owner = $this->student();
        $other = $this->teacher();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $notification = UserNotification::factory()->create(['user_id' => $owner]);

        $this->assertTrue($owner->can('view', $notification));
        $this->assertTrue($owner->can('markRead', $notification));
        $this->assertFalse($other->can('view', $notification));
        $this->assertFalse($other->can('markRead', $notification));
        $this->assertFalse($superAdmin->can('view', $notification));
        $this->assertFalse($superAdmin->can('markRead', $notification));
    }

    public function test_mark_one_read_is_idempotent_and_denies_other_users(): void
    {
        $owner = $this->student();
        $other = $this->student();
        $notification = UserNotification::factory()->create(['user_id' => $owner]);
        $action = app(MarkUserNotificationRead::class);

        $marked = $action->handle($owner, $notification);
        $firstReadAt = $marked->read_at;
        $markedAgain = $action->handle($owner, $marked);
        $this->assertTrue($firstReadAt->equalTo($markedAgain->read_at));

        $this->expectException(AuthorizationException::class);
        $action->handle($other, $notification);
    }

    public function test_mark_all_read_updates_only_the_authenticated_users_rows(): void
    {
        $owner = $this->student();
        $other = $this->student();
        UserNotification::factory()->count(2)->create(['user_id' => $owner]);
        UserNotification::factory()->create(['user_id' => $owner, 'read_at' => now()->subDay()]);
        $otherNotification = UserNotification::factory()->create(['user_id' => $other]);

        $updated = app(MarkAllUserNotificationsRead::class)->handle($owner);

        $this->assertSame(2, $updated);
        $this->assertSame(0, UserNotification::query()->where('user_id', $owner->id)->whereNull('read_at')->count());
        $this->assertNull($otherNotification->refresh()->read_at);
    }

    public function test_notification_permissions_and_deployment_verifier_are_synchronized(): void
    {
        foreach (['Super Admin', 'Admin', 'Teacher', 'Student'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->assertTrue($user->can('notifications.view'));
            $this->assertTrue($user->can('notifications.mark-read'));
        }

        $this->artisan('notifications:verify-permissions')->assertSuccessful();
        Permission::findByName('notifications.mark-read')->delete();
        $this->artisan('notifications:verify-permissions')
            ->expectsOutputToContain('Notification RBAC is not synchronized.')
            ->assertFailed();
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Student');

        return $user;
    }

    private function teacher(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Teacher');

        return $user;
    }
}
