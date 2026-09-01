<?php

namespace Tests\Feature\Phase13;

use App\Events\ConversationMessageSent;
use App\Models\AcademicYear;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PrivateConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_shared_class_members_open_one_canonical_direct_conversation(): void
    {
        [$class, $a, $b] = $this->sharedMembers();
        $this->actingAs($a)->post(route('classes.members.chat', [$class, $b]))->assertRedirect();
        $this->actingAs($b)->post(route('classes.members.chat', [$class, $a]))->assertRedirect();
        $this->assertDatabaseCount('conversations', 1);
        $conversation = Conversation::first();
        $this->assertSame('direct', $conversation->type);
        $this->assertSame(2, $conversation->members()->whereNull('left_at')->count());
    }

    public function test_self_and_forged_unrelated_direct_chats_are_rejected(): void
    {
        [$class, $a] = $this->sharedMembers();
        $outside = $this->student();
        $this->actingAs($a)->post(route('classes.members.chat', [$class, $a]))->assertSessionHasErrors('recipient');
        $this->actingAs($a)->post(route('classes.members.chat', [$class, $outside]))->assertForbidden();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_only_active_members_can_view_send_or_access_private_conversations_even_super_admin(): void
    {
        [, $a, $b] = $this->sharedMembers();
        $conversation = $this->direct($a, $b);
        $admin = $this->user('Super Admin');
        $this->actingAs($admin)->get(route('conversations.show', $conversation))->assertForbidden();
        $this->actingAs($admin)->post(route('conversations.messages.store', $conversation), ['body' => 'private'])->assertForbidden();
        ConversationMember::query()->where('conversation_id', $conversation->id)->where('user_id', $b->id)->update(['left_at' => now()]);
        $this->actingAs($b)->get(route('conversations.show', $conversation))->assertForbidden();
        $this->actingAs($b)->post(route('conversations.messages.store', $conversation), ['body' => 'private'])->assertForbidden();
    }

    public function test_messages_are_server_owned_bounded_and_broadcast_safe_data(): void
    {
        [, $a, $b] = $this->sharedMembers();
        $conversation = $this->direct($a, $b);
        Event::fake([ConversationMessageSent::class]);
        $this->actingAs($a)->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hello privately'])->assertCreated()->assertJsonPath('message.sender.id', $a->id);
        $this->assertDatabaseHas('conversation_messages', ['conversation_id' => $conversation->id, 'sender_user_id' => $a->id, 'body' => 'Hello privately']);
        Event::assertDispatched(ConversationMessageSent::class, fn ($event) => $event->broadcastOn()[0] instanceof PrivateChannel
            && $event->broadcastOn()[0]->name === 'private-conversation.'.$conversation->public_uuid
            && $event->broadcastAs() === 'conversation.message.sent'
            && $event->broadcastWith()['message']['sender']['id'] === $a->id
            && ! array_key_exists('email', $event->broadcastWith()['message']['sender']));
        $this->actingAs($a)->postJson(route('conversations.messages.store', $conversation), ['body' => str_repeat('x', 4001)])->assertUnprocessable();
    }

    public function test_members_cannot_inject_messages_into_another_conversation(): void
    {
        [, $a, $b] = $this->sharedMembers();
        $first = $this->direct($a, $b);
        $second = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $second->id, 'user_id' => $b->id]);
        $this->actingAs($a)->postJson(route('conversations.messages.store', $second), ['body' => 'forged'])->assertForbidden();
        $this->assertDatabaseMissing('conversation_messages', ['conversation_id' => $second->id, 'body' => 'forged']);
        $this->actingAs($a)->get(route('conversations.show', $first))->assertOk();
    }

    public function test_group_management_is_manager_scoped_and_manager_leave_promotes_oldest_member(): void
    {
        [, $a, $b] = $this->sharedMembers();
        $c = $this->memberOf($this->classFor($a));
        $this->actingAs($a)->post(route('conversations.groups.store'), ['name' => 'Study group', 'member_ids' => [$b->id, $c->id]])->assertRedirect();
        $group = Conversation::where('type', 'group')->firstOrFail();
        $this->assertDatabaseHas('conversation_members', ['conversation_id' => $group->id, 'user_id' => $a->id, 'role' => 'manager']);
        $this->actingAs($b)->patch(route('conversations.update', $group), ['name' => 'Forged'])->assertForbidden();
        $this->actingAs($a)->patch(route('conversations.update', $group), ['name' => 'Renamed study group'])->assertRedirect();
        $this->assertDatabaseHas('conversations', ['id' => $group->id, 'name' => 'Renamed study group']);
        $this->actingAs($a)->post(route('conversations.leave', $group))->assertRedirect(route('conversations.index'));
        $this->assertDatabaseHas('conversation_members', ['conversation_id' => $group->id, 'user_id' => $b->id, 'role' => 'manager', 'left_at' => null]);
        $this->actingAs($b)->delete(route('conversations.members.destroy', [$group, $c]))->assertRedirect();
        $this->assertDatabaseMissing('conversation_members', ['conversation_id' => $group->id, 'user_id' => $c->id, 'left_at' => null]);
    }

    public function test_group_members_must_be_discoverable_and_duplicate_active_memberships_are_prevented(): void
    {
        [, $a, $b] = $this->sharedMembers();
        $outside = $this->student();
        $this->actingAs($a)->post(route('conversations.groups.store'), ['name' => 'Study group', 'member_ids' => [$outside->id]])->assertSessionHasErrors('member_ids');
        $this->actingAs($a)->post(route('conversations.groups.store'), ['name' => 'Study group', 'member_ids' => [$b->id]])->assertRedirect();
        $group = Conversation::where('type', 'group')->firstOrFail();
        $this->actingAs($a)->post(route('conversations.members.store', $group), ['member_ids' => [$b->id]])->assertRedirect();
        $this->assertSame(1, $group->members()->where('user_id', $b->id)->count());
    }

    public function test_final_group_member_can_leave_without_deleting_history(): void
    {
        $user = $this->student();
        $group = Conversation::factory()->create(['created_by_user_id' => $user->id]);
        ConversationMember::factory()->create(['conversation_id' => $group->id, 'user_id' => $user->id, 'role' => 'manager']);
        $group->messages()->create(['sender_user_id' => $user->id, 'body' => 'Keep this history']);
        $this->actingAs($user)->post(route('conversations.leave', $group))->assertRedirect(route('conversations.index'));
        $this->assertDatabaseHas('conversation_members', ['conversation_id' => $group->id, 'user_id' => $user->id]);
        $this->assertNotNull(ConversationMember::where('conversation_id', $group->id)->where('user_id', $user->id)->value('left_at'));
        $this->assertDatabaseHas('conversation_messages', ['conversation_id' => $group->id, 'body' => 'Keep this history']);
    }

    public function test_class_member_payload_has_real_chat_url_for_other_members_only(): void
    {
        [$class, $a, $b] = $this->sharedMembers();
        $this->actingAs($a)->get(route('classes.members', $class))->assertInertia(fn ($page) => $page
            ->where('members', fn ($members) => collect($members)->firstWhere('id', $a->id)['chatUrl'] === null
                && collect($members)->firstWhere('id', $b->id)['chatUrl'] === route('classes.members.chat', [$class, $b])));
    }

    private function sharedMembers(): array
    {
        $year = AcademicYear::factory()->active()->create();
        $class = SchoolClass::factory()->create(['academic_year_id' => $year->id]);
        $a = $this->memberOf($class);
        $b = $this->memberOf($class);

        return [$class, $a, $b];
    }

    private function classFor(User $user): SchoolClass
    {
        return SchoolClass::whereHas('enrollments.studentProfile', fn ($q) => $q->where('user_id', $user->id))->firstOrFail();
    }

    private function memberOf(SchoolClass $class): User
    {
        $user = $this->student();
        Enrollment::factory()->create(['student_profile_id' => StudentProfile::factory()->create(['user_id' => $user->id])->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => 1]);

        return $user;
    }

    private function student(): User
    {
        return $this->user('Student');
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }

    private function direct(User $a, User $b): Conversation
    {
        $conversation = Conversation::factory()->direct()->create(['direct_pair_key' => collect([$a->id, $b->id])->sort()->implode(':')]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $a->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $b->id]);

        return $conversation;
    }
}
