<?php

namespace Tests\Feature\Phase15;

use App\Events\ConversationCallSignal;
use App\Events\ConversationMembershipChanged;
use App\Events\ConversationMessageSent;
use App\Listeners\Notifications\CreateConversationCallOutcomeNotifications;
use App\Listeners\Notifications\CreateConversationMembershipNotifications;
use App\Listeners\Notifications\CreateConversationMessageNotifications;
use App\Models\Conversation;
use App\Models\ConversationCall;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ActivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_direct_message_activity_notifies_only_the_other_member_without_storing_its_body(): void
    {
        [$sender, $recipient, $conversation] = $this->conversation();
        $message = $conversation->messages()->create(['sender_user_id' => $sender->id, 'body' => 'Private answer that must stay in chat']);
        $listener = app(CreateConversationMessageNotifications::class);
        $listener->handle(new ConversationMessageSent($message));
        $listener->handle(new ConversationMessageSent($message));

        $notification = UserNotification::query()->sole();
        $this->assertSame($recipient->id, $notification->user_id);
        $this->assertSame('conversation.direct-message', $notification->type);
        $this->assertSame(['actor_name' => $sender->name], $notification->context);
        $this->assertStringNotContainsString('Private answer', json_encode($notification->toArray()));
        $this->assertSame('conversations.show', $notification->route_name);
    }

    public function test_group_messages_exclude_sender_and_removed_members(): void
    {
        [$sender, $recipient, $conversation] = $this->conversation('group');
        $removed = $this->user();
        $conversation->members()->create(['user_id' => $removed->id, 'role' => 'member', 'joined_at' => now(), 'left_at' => now()]);
        $message = $conversation->messages()->create(['sender_user_id' => $sender->id, 'body' => 'Private group work']);

        app(CreateConversationMessageNotifications::class)->handle(new ConversationMessageSent($message));

        $this->assertDatabaseHas('user_notifications', ['user_id' => $recipient->id, 'type' => 'conversation.group-message']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $sender->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $removed->id]);
    }

    public function test_removed_group_member_keeps_safe_history_without_a_deep_link(): void
    {
        [$actor, $recipient, $conversation] = $this->conversation('group');
        $member = $conversation->members()->where('user_id', $recipient->id)->firstOrFail();
        $member->update(['left_at' => now()]);
        app(CreateConversationMembershipNotifications::class)->handle(new ConversationMembershipChanged($conversation, $member, $actor, 'removed'));

        $this->actingAs($recipient)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('notifications.data.0.href', null)
            ->where('notifications.data.0.context', ['message' => 'Your access to a group conversation changed.']));
    }

    public function test_added_and_promoted_members_receive_safe_group_activity(): void
    {
        [$actor, $recipient, $conversation] = $this->conversation('group');
        $member = $conversation->members()->where('user_id', $recipient->id)->firstOrFail();
        $listener = app(CreateConversationMembershipNotifications::class);
        $listener->handle(new ConversationMembershipChanged($conversation, $member, $actor, 'added'));
        $listener->handle(new ConversationMembershipChanged($conversation, $member, $actor, 'promoted'));

        $this->assertDatabaseHas('user_notifications', ['user_id' => $recipient->id, 'type' => 'conversation.member-added']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $recipient->id, 'type' => 'conversation.member-promoted']);
    }

    public function test_missed_and_declined_direct_calls_create_sanitized_recipient_scoped_activity(): void
    {
        [$caller, $recipient, $conversation] = $this->conversation();
        $call = ConversationCall::create(['conversation_id' => $conversation->id, 'initiated_by_user_id' => $caller->id, 'type' => 'video', 'status' => 'cancelled', 'ended_at' => now()]);
        $call->participants()->createMany([['user_id' => $caller->id, 'invited_at' => now()], ['user_id' => $recipient->id, 'invited_at' => now()]]);
        $listener = app(CreateConversationCallOutcomeNotifications::class);
        $listener->handle(new ConversationCallSignal($call, 'cancelled'));
        $call->update(['status' => 'declined']);
        $call->participants()->where('user_id', $recipient->id)->update(['declined_at' => now()]);
        $listener->handle(new ConversationCallSignal($call, 'declined'));

        $missed = UserNotification::query()->where('type', 'conversation.call-missed')->sole();
        $declined = UserNotification::query()->where('type', 'conversation.call-declined')->sole();
        $this->assertSame($recipient->id, $missed->user_id);
        $this->assertSame($caller->id, $declined->user_id);
        $this->assertStringNotContainsString($call->livekit_room_name, json_encode([$missed->toArray(), $declined->toArray()]));
    }

    public function test_call_ringing_is_transient_and_never_creates_activity(): void
    {
        [$caller, $recipient, $conversation] = $this->conversation();
        $call = ConversationCall::create(['conversation_id' => $conversation->id, 'initiated_by_user_id' => $caller->id, 'type' => 'audio', 'status' => 'ringing']);
        $call->participants()->createMany([['user_id' => $caller->id, 'invited_at' => now()], ['user_id' => $recipient->id, 'invited_at' => now()]]);

        app(CreateConversationCallOutcomeNotifications::class)->handle(new ConversationCallSignal($call, 'started'));

        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_super_admin_cannot_read_another_users_activity(): void
    {
        [, $recipient] = $this->conversation();
        $notification = UserNotification::factory()->create(['user_id' => $recipient->id]);
        $superAdmin = $this->user('Super Admin');

        $this->actingAs($superAdmin)->patch(route('notifications.read', $notification->public_id))->assertNotFound();
    }

    public function test_shared_inbox_preview_only_includes_the_viewers_own_activity(): void
    {
        $viewer = $this->user('Student');
        $otherUser = $this->user('Student');
        UserNotification::factory()->create(['user_id' => $otherUser->id]);
        $own = UserNotification::factory()->create([
            'user_id' => $viewer->id,
            'type' => 'conversation.direct-message',
            'context' => ['actor_name' => 'Peer'],
        ]);

        $this->actingAs($viewer)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('notificationInbox.unread_count', 1)
            ->where('notificationInbox.preview', fn ($preview) => count($preview) === 1 && $preview[0]['public_id'] === $own->public_id));
    }

    private function conversation(string $type = 'direct'): array
    {
        $sender = $this->user();
        $recipient = $this->user();
        $conversation = Conversation::factory()->create(['type' => $type, 'name' => $type === 'group' ? 'Study Group' : null, 'created_by_user_id' => $sender->id]);
        $conversation->members()->createMany([['user_id' => $sender->id, 'role' => 'manager', 'joined_at' => now()], ['user_id' => $recipient->id, 'role' => 'member', 'joined_at' => now()]]);

        return [$sender, $recipient, $conversation];
    }

    private function user(string $role = 'Student'): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }
}
