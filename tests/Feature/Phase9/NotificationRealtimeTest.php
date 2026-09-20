<?php

namespace Tests\Feature\Phase9;

use App\Enums\ChannelType;
use App\Enums\SchoolClassStatus;
use App\Events\AnnouncementPublished;
use App\Events\UserNotificationCreated;
use App\Listeners\Notifications\CreateAnnouncementPublishedNotifications;
use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Channel;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotificationRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_private_notification_channel_is_strictly_self_scoped(): void
    {
        $user = $this->student();
        $other = $this->student();
        $channels = app(BroadcastManager::class)->driver()->getChannels();
        $authorize = $channels['notifications.{userId}'];

        $this->assertTrue($authorize($user, $user->id));
        $this->assertFalse($authorize($user, $other->id));
        $this->assertFalse($authorize($other, $user->id));
        $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-notifications.'.$user->id,
        ])->assertOk();
    }

    public function test_inactive_unverified_and_permissionless_users_cannot_authorize_channel(): void
    {
        $channels = app(BroadcastManager::class)->driver()->getChannels();
        $authorize = $channels['notifications.{userId}'];
        $inactive = User::factory()->create(['status' => 'inactive']);
        $inactive->assignRole('Student');
        $unverified = User::factory()->unverified()->create();
        $unverified->assignRole('Student');
        $permissionless = User::factory()->create();

        $this->assertFalse($authorize($inactive, $inactive->id));
        $this->assertFalse($authorize($unverified, $unverified->id));
        $this->assertFalse($authorize($permissionless, $permissionless->id));
    }

    public function test_realtime_event_uses_private_channel_and_minimal_versioned_payload(): void
    {
        $recipient = $this->student();
        $notification = UserNotification::factory()->create([
            'user_id' => $recipient,
            'context' => ['title' => 'Private assignment title'],
            'route_name' => 'dashboard',
            'route_parameters' => ['secret' => 'not-for-broadcast'],
        ]);
        $event = new UserNotificationCreated($notification);
        $payload = $event->broadcastWith();
        $serialized = json_encode($payload);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('private-notifications.'.$recipient->id, $event->broadcastOn()[0]->name);
        $this->assertSame('notification.created', $event->broadcastAs());
        $this->assertSame(['notification', 'unread_count', 'schema_version'], array_keys($payload));
        $this->assertSame(['public_id', 'type', 'created_at'], array_keys($payload['notification']));
        $this->assertSame(1, $payload['unread_count']);
        $this->assertSame(1, $payload['schema_version']);
        foreach (['Private assignment title', 'not-for-broadcast', 'route_name', 'route_parameters', 'deduplication_key', 'actor_id', 'subject_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_producer_emits_once_only_after_new_notification_is_persisted(): void
    {
        [$student, $class] = $this->enrolledStudent();
        $author = User::factory()->create();
        $author->assignRole('Admin');
        $channel = Channel::factory()->create(['school_class_id' => $class, 'type' => ChannelType::Announcement, 'default_slot' => 2]);
        $announcement = Announcement::factory()->published()->create(['channel_id' => $channel, 'author_id' => $author]);
        Event::fake([UserNotificationCreated::class]);
        $listener = app(CreateAnnouncementPublishedNotifications::class);

        $listener->handle(new AnnouncementPublished($announcement));
        $listener->handle(new AnnouncementPublished($announcement));

        $this->assertDatabaseCount('user_notifications', 1);
        Event::assertDispatchedTimes(UserNotificationCreated::class, 1);
        Event::assertDispatched(UserNotificationCreated::class, function ($event) use ($student) {
            return UserNotification::query()->whereKey($event->notification->id)->where('user_id', $student->id)->exists();
        });
    }

    public function test_realtime_unread_metadata_reconciles_with_mark_one_and_mark_all_http_state(): void
    {
        $user = $this->student();
        $first = UserNotification::factory()->create(['user_id' => $user]);
        $second = UserNotification::factory()->create(['user_id' => $user]);
        $event = new UserNotificationCreated($second);
        $this->assertSame(2, $event->broadcastWith()['unread_count']);

        $this->actingAs($user)->patch(route('notifications.read', $first->public_id))->assertRedirect();
        $this->assertSame(1, $event->broadcastWith()['unread_count']);
        $this->actingAs($user)->patch(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $event->broadcastWith()['unread_count']);
    }

    public function test_frontend_reuses_echo_singleton_and_reconciles_events_reconnects_and_tabs(): void
    {
        $hook = file_get_contents(resource_path('js/Hooks/useNotificationRealtime.js'));
        $echo = file_get_contents(resource_path('js/realtime/echo.js'));
        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

        $this->assertSame(1, substr_count($echo, 'new Echo('));
        $this->assertStringContainsString("import {echo} from '../realtime/echo'", $hook);
        $this->assertStringNotContainsString('new Echo(', $hook);
        $this->assertStringContainsString("echo.private(name).listen('.notification.created'", $hook);
        $this->assertStringContainsString("socket.bind('connected', connected)", $hook);
        $this->assertStringContainsString("window.addEventListener('focus', focused)", $hook);
        $this->assertStringContainsString("document.addEventListener('visibilitychange', visible)", $hook);
        $this->assertStringContainsString("only: inboxOpen ? ['notificationInbox', 'notifications'] : ['notificationInbox']", $hook);
        $this->assertStringContainsString('echo.leave(name)', $hook);
        $this->assertStringContainsString('useNotificationRealtime', $layout);
    }

    public function test_live_notification_keeps_unread_count_current_while_full_meeting_defers_inertia_reconciliation(): void
    {
        $hook = file_get_contents(resource_path('js/Hooks/useNotificationRealtime.js'));

        $this->assertStringContainsString('if (Number.isInteger(event?.unread_count)) setUnreadCount(event.unread_count);', $hook);
        $this->assertStringContainsString('if (pauseBackgroundRefreshRef.current) {', $hook);
        $this->assertStringContainsString('refreshDeferred.current = true;', $hook);
        $this->assertStringContainsString('if (!pauseBackgroundRefresh && refreshDeferred.current) {', $hook);
        $this->assertStringContainsString('refreshDeferred.current = false;', $hook);
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Student');

        return $user;
    }

    /** @return array{User, SchoolClass} */
    private function enrolledStudent(): array
    {
        $year = AcademicYear::factory()->active()->create();
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);
        $student = $this->student();
        $profile = StudentProfile::factory()->create(['user_id' => $student]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile,
            'academic_year_id' => $year,
            'school_class_id' => $class,
            'current_slot' => 1,
        ]);

        return [$student->refresh(), $class];
    }
}
