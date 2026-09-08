<?php

namespace Tests\Feature\Phase17;

use App\Events\ConversationMutationChanged;
use App\Events\ConversationTyping;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\ConversationMessageReaction;
use App\Models\ConversationMessageRead;
use App\Models\ConversationPin;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationMessageExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_members_can_reply_edit_tombstone_react_read_pin_search_and_emit_safe_private_events(): void
    {
        [$conversation, $sender, $member] = $this->conversation();
        $message = $conversation->messages()->create(['sender_user_id' => $sender->id, 'body' => 'Original']);
        Event::fake();
        $this->actingAs($member)->postJson(route('conversations.messages.store', $conversation), ['body' => 'Reply', 'reply_to_message_id' => $message->id])->assertCreated()->assertJsonPath('message.reply.id', $message->id);
        $this->actingAs($sender)->patchJson(route('conversations.messages.update', [$conversation, $message]), ['body' => 'Edited'])->assertOk();
        Event::assertDispatched(ConversationMutationChanged::class, fn ($event) => $this->isSafePrivateMutation($event, $conversation, 'conversation.message.edited'));
        $this->actingAs($member)->postJson(route('conversations.messages.react', [$conversation, $message]), ['emoji' => '👍'])->assertOk()->assertJsonPath('reactions.👍', 1);
        $this->actingAs($member)->postJson(route('conversations.messages.read', $conversation), ['message_ids' => [$message->id]])->assertOk();
        $this->actingAs($member)->getJson(route('conversations.messages.search', [$conversation, 'q' => 'Edited']))->assertOk()->assertJsonCount(1, 'results');
        $this->actingAs($sender)->putJson(route('conversations.pin', [$conversation, $message]))->assertOk();
        $this->actingAs($sender)->deleteJson(route('conversations.messages.destroy', [$conversation, $message]))->assertOk();
        $this->assertNull($message->fresh()->body);
        Event::assertDispatched(ConversationMutationChanged::class, fn ($event) => $this->isSafePrivateMutation($event, $conversation, 'conversation.message.reactions'));
        Event::assertDispatched(ConversationMutationChanged::class, fn ($event) => $this->isSafePrivateMutation($event, $conversation, 'conversation.messages.read'));
        Event::assertDispatched(ConversationMutationChanged::class, fn ($event) => $this->isSafePrivateMutation($event, $conversation, 'conversation.pin.changed'));
        Event::assertDispatched(ConversationMutationChanged::class, fn ($event) => $this->isSafePrivateMutation($event, $conversation, 'conversation.message.deleted') && ! array_key_exists('body', $event->broadcastWith()));
    }

    public function test_cross_conversation_and_non_member_super_admin_operations_are_denied(): void
    {
        [$conversation, $sender] = $this->conversation();
        [$other] = $this->conversation();
        $message = $other->messages()->create(['sender_user_id' => $sender->id, 'body' => 'Other']);
        $super = User::factory()->create();
        $super->assignRole('Super Admin');
        $this->actingAs($sender)->patchJson(route('conversations.messages.update', [$conversation, $message]), ['body' => 'Forged'])->assertNotFound();
        $this->actingAs($super)->postJson(route('conversations.typing', $conversation), ['typing' => true])->assertForbidden();
        $this->actingAs($super)->getJson(route('conversations.messages.search', [$conversation, 'q' => 'x']))->assertForbidden();
    }

    public function test_typing_is_private_server_owned_and_not_persisted(): void
    {
        [$conversation, $member] = $this->conversation();
        Event::fake([ConversationTyping::class]);
        $before = AuditLog::count();
        $notifications = UserNotification::count();
        $this->actingAs($member)->postJson(route('conversations.typing', $conversation), ['typing' => true, 'name' => 'Forged'])->assertOk();
        Event::assertDispatched(ConversationTyping::class, fn ($event) => $event->user->is($member) && $event->broadcastOn()[0] instanceof PrivateChannel && $event->broadcastOn()[0]->name === 'private-conversation.'.$conversation->public_uuid && $event->broadcastAs() === 'conversation.typing' && $event->broadcastWith()['user']['name'] === $member->name);
        $this->assertSame($before, AuditLog::count());
        $this->assertSame($notifications, UserNotification::count());
    }

    public function test_non_member_super_admin_is_denied_every_private_message_operation(): void
    {
        [$conversation, $sender, $member] = $this->conversation();
        $message = $this->message($conversation, $sender, 'Private');
        $super = User::factory()->create();
        $super->assignRole('Super Admin');

        $this->actingAs($super)->postJson(route('conversations.messages.store', $conversation), ['body' => 'No', 'reply_to_message_id' => $message->id])->assertForbidden();
        $this->actingAs($super)->patchJson(route('conversations.messages.update', [$conversation, $message]), ['body' => 'No'])->assertForbidden();
        $this->actingAs($super)->deleteJson(route('conversations.messages.destroy', [$conversation, $message]))->assertForbidden();
        $this->actingAs($super)->postJson(route('conversations.messages.react', [$conversation, $message]), ['emoji' => '👍'])->assertForbidden();
        $this->actingAs($super)->postJson(route('conversations.messages.read', $conversation), ['message_ids' => [$message->id]])->assertForbidden();
        $this->actingAs($super)->putJson(route('conversations.pin', [$conversation, $message]))->assertForbidden();
        $this->actingAs($super)->deleteJson(route('conversations.pin.destroy', $conversation))->assertForbidden();
        $this->actingAs($super)->getJson(route('conversations.messages.search', [$conversation, 'q' => 'Private']))->assertForbidden();
        $this->actingAs($super)->postJson(route('conversations.messages.forward', [$conversation, $message]), ['destination_conversation' => $conversation->public_uuid])->assertForbidden();
        $this->actingAs($super)->postJson(route('conversations.typing', $conversation), ['typing' => true])->assertForbidden();

        $this->assertTrue($conversation->members()->where('user_id', $member->id)->exists());
    }

    public function test_cross_conversation_message_identifiers_cannot_be_used_by_a_valid_member(): void
    {
        [$conversation, $sender, $member] = $this->conversation();
        [$other, $otherSender] = $this->conversation();
        $foreign = $this->message($other, $otherSender, 'Foreign');
        $local = $this->message($conversation, $sender, 'Local');

        $this->actingAs($sender)->postJson(route('conversations.messages.store', $conversation), ['body' => 'Reply', 'reply_to_message_id' => $foreign->id])->assertNotFound();
        $this->actingAs($sender)->patchJson(route('conversations.messages.update', [$conversation, $foreign]), ['body' => 'No'])->assertNotFound();
        $this->actingAs($sender)->deleteJson(route('conversations.messages.destroy', [$conversation, $foreign]))->assertNotFound();
        $this->actingAs($member)->postJson(route('conversations.messages.react', [$conversation, $foreign]), ['emoji' => '👍'])->assertNotFound();
        $this->actingAs($member)->postJson(route('conversations.messages.read', $conversation), ['message_ids' => [$foreign->id]])->assertNotFound();
        $this->actingAs($sender)->putJson(route('conversations.pin', [$conversation, $foreign]))->assertNotFound();
        $this->actingAs($member)->postJson(route('conversations.messages.forward', [$conversation, $foreign]), ['destination_conversation' => $conversation->public_uuid])->assertNotFound();
        $this->actingAs($sender)->postJson(route('conversations.messages.forward', [$conversation, $local]), ['destination_conversation' => $other->public_uuid])->assertForbidden();
    }

    public function test_search_is_conversation_scoped_tombstone_safe_and_bounded(): void
    {
        [$conversation, $sender] = $this->conversation();
        [$other, $otherSender] = $this->conversation();
        foreach (range(1, 30) as $index) {
            $this->message($conversation, $sender, 'Needle '.$index);
        }
        $tombstoned = $this->message($conversation, $sender, 'Needle hidden');
        $tombstoned->update(['body' => null, 'deleted_at' => now()]);
        $this->message($other, $otherSender, 'Needle foreign');

        $response = $this->actingAs($sender)->getJson(route('conversations.messages.search', [$conversation, 'q' => 'Needle']))->assertOk()->assertJsonCount(25, 'results');
        $bodies = collect($response->json('results'))->pluck('body');
        $this->assertFalse($bodies->contains('Needle hidden'));
        $this->assertFalse($bodies->contains('Needle foreign'));
    }

    public function test_reactions_and_read_receipts_are_server_owned_idempotent_and_member_scoped(): void
    {
        [$conversation, $sender, $member] = $this->conversation();
        $message = $this->message($conversation, $sender, 'React to me');
        $outsider = User::factory()->create();

        $this->actingAs($member)->postJson(route('conversations.messages.react', [$conversation, $message]), ['emoji' => '👍'])->assertOk()->assertJsonPath('reactions.👍', 1);
        $this->assertDatabaseCount('conversation_message_reactions', 1);
        $this->actingAs($member)->postJson(route('conversations.messages.react', [$conversation, $message]), ['emoji' => '👍'])->assertOk();
        $this->assertDatabaseCount('conversation_message_reactions', 0);
        $this->actingAs($member)->postJson(route('conversations.messages.react', [$conversation, $message]), ['emoji' => '<script>'])->assertUnprocessable();
        $this->actingAs($outsider)->postJson(route('conversations.messages.react', [$conversation, $message]), ['emoji' => '👍'])->assertForbidden();

        $this->actingAs($member)->postJson(route('conversations.messages.read', $conversation), ['message_ids' => [$message->id]])->assertOk();
        $this->actingAs($member)->postJson(route('conversations.messages.read', $conversation), ['message_ids' => [$message->id]])->assertOk();
        $this->assertSame(1, ConversationMessageRead::query()->where('conversation_message_id', $message->id)->where('user_id', $member->id)->count());
        $this->actingAs($sender)->postJson(route('conversations.messages.read', $conversation), ['message_ids' => [$message->id]])->assertNotFound();
        $this->assertSame(0, ConversationMessageReaction::query()->count());
    }

    public function test_group_pin_requires_a_manager_and_unpin_emits_a_safe_private_event(): void
    {
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $group = Conversation::factory()->create(['created_by_user_id' => $manager->id]);
        ConversationMember::factory()->create(['conversation_id' => $group->id, 'user_id' => $manager->id, 'role' => 'manager']);
        ConversationMember::factory()->create(['conversation_id' => $group->id, 'user_id' => $member->id, 'role' => 'member']);
        $message = $this->message($group, $manager, 'Pin me');
        Event::fake([ConversationMutationChanged::class]);

        $this->actingAs($member)->putJson(route('conversations.pin', [$group, $message]))->assertForbidden();
        $this->actingAs($manager)->putJson(route('conversations.pin', [$group, $message]))->assertOk();
        $this->assertSame(1, ConversationPin::query()->where('conversation_id', $group->id)->count());
        $this->actingAs($manager)->deleteJson(route('conversations.pin.destroy', $group))->assertOk();
        Event::assertDispatched(ConversationMutationChanged::class, fn ($event) => $this->isSafePrivateMutation($event, $group, 'conversation.pin.changed') && $event->broadcastWith()['pinned_message_id'] === null);
    }

    public function test_forwarding_copies_private_attachments_and_keeps_each_conversation_scoped(): void
    {
        Storage::fake('local');
        [$source, $sender, $sourceOnly] = $this->conversation();
        [$destination, $destinationMember] = $this->conversation();
        ConversationMember::factory()->create(['conversation_id' => $destination->id, 'user_id' => $sender->id]);
        $message = $this->message($source, $sender, 'Text with file');
        $sourceAttachment = $this->attachment($message);

        $response = $this->actingAs($sender)->postJson(route('conversations.messages.forward', [$source, $message]), ['destination_conversation' => $destination->public_uuid])->assertCreated();
        $forwarded = ConversationMessage::query()->findOrFail($response->json('message_id'));
        $destinationAttachment = $forwarded->attachments()->sole();

        $this->assertSame('Text with file', $forwarded->body);
        $this->assertNotSame($sourceAttachment->id, $destinationAttachment->id);
        $this->assertNotSame($sourceAttachment->public_uuid, $destinationAttachment->public_uuid);
        $this->assertNotSame($sourceAttachment->path, $destinationAttachment->path);
        $this->assertStringContainsString('conversation-attachments/'.$destination->public_uuid.'/', $destinationAttachment->path);
        Storage::disk('local')->assertExists($destinationAttachment->path);
        $this->assertStringNotContainsString($sourceAttachment->path, json_encode($response->json()));

        $this->actingAs($destinationMember)->get(route('conversations.attachments.show', [$destination, $destinationAttachment]))->assertOk();
        $this->actingAs($sourceOnly)->get(route('conversations.attachments.show', [$destination, $destinationAttachment]))->assertForbidden();
        $this->actingAs($destinationMember)->get(route('conversations.attachments.show', [$source, $sourceAttachment]))->assertForbidden();
    }

    public function test_attachment_only_and_text_only_forwards_succeed_but_tombstoned_messages_cannot_be_forwarded(): void
    {
        Storage::fake('local');
        [$source, $sender] = $this->conversation();
        [$destination] = $this->conversation();
        ConversationMember::factory()->create(['conversation_id' => $destination->id, 'user_id' => $sender->id]);
        $text = $this->message($source, $sender, 'Text only');
        $attachmentOnly = $this->message($source, $sender, null);
        $this->attachment($attachmentOnly);
        $deleted = $this->message($source, $sender, 'Gone');
        $deleted->update(['body' => null, 'deleted_at' => now()]);

        $this->actingAs($sender)->postJson(route('conversations.messages.forward', [$source, $text]), ['destination_conversation' => $destination->public_uuid])->assertCreated();
        $this->actingAs($sender)->postJson(route('conversations.messages.forward', [$source, $attachmentOnly]), ['destination_conversation' => $destination->public_uuid])->assertCreated();
        $this->actingAs($sender)->postJson(route('conversations.messages.forward', [$source, $deleted]), ['destination_conversation' => $destination->public_uuid])->assertUnprocessable();
    }

    public function test_forwarding_requires_both_memberships_and_cleans_up_when_a_source_file_is_missing(): void
    {
        Storage::fake('local');
        [$source, $sender] = $this->conversation();
        [$destination] = $this->conversation();
        $message = $this->message($source, $sender, 'Source');
        $attachment = $this->attachment($message);
        $before = ConversationMessage::query()->count();

        $this->actingAs($sender)->postJson(route('conversations.messages.forward', [$source, $message]), ['destination_conversation' => $destination->public_uuid])->assertForbidden();
        ConversationMember::factory()->create(['conversation_id' => $destination->id, 'user_id' => $sender->id]);
        $missing = $message->attachments()->create([
            'disk' => 'local',
            'path' => 'conversation-attachments/'.$source->public_uuid.'/'.$message->id.'/missing.mp3',
            'original_name' => 'missing.mp3',
            'extension' => 'mp3',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => 1,
            'attachment_type' => 'voice',
        ]);
        $this->actingAs($sender)->postJson(route('conversations.messages.forward', [$source, $message]), ['destination_conversation' => $destination->public_uuid])->assertUnprocessable();
        $this->assertSame($before, ConversationMessage::query()->count());
        $this->assertDatabaseCount('conversation_message_attachments', 2);
        $this->assertSame([], Storage::disk('local')->allFiles('conversation-attachments/'.$destination->public_uuid));
        $this->assertFalse(Storage::disk('local')->exists($missing->path));
    }

    public function test_tombstoning_hides_existing_protected_attachments(): void
    {
        Storage::fake('local');
        [$conversation, $sender] = $this->conversation();
        $message = $this->message($conversation, $sender, 'Remove this');
        $attachment = $this->attachment($message);

        $this->actingAs($sender)->deleteJson(route('conversations.messages.destroy', [$conversation, $message]))->assertOk();
        $this->actingAs($sender)->get(route('conversations.attachments.show', [$conversation, $attachment]))->assertNotFound();
    }

    private function conversation(): array
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = Conversation::factory()->direct()->create(['created_by_user_id' => $a->id]);
        ConversationMember::factory()->create(['conversation_id' => $c->id, 'user_id' => $a->id]);
        ConversationMember::factory()->create(['conversation_id' => $c->id, 'user_id' => $b->id]);

        return [$c, $a, $b];
    }

    private function message(Conversation $conversation, User $sender, ?string $body): ConversationMessage
    {
        return $conversation->messages()->create(['sender_user_id' => $sender->id, 'body' => $body]);
    }

    private function attachment(ConversationMessage $message): ConversationMessageAttachment
    {
        $path = 'conversation-attachments/'.$message->conversation->public_uuid.'/'.$message->id.'/source.mp3';
        Storage::disk('local')->put($path, 'voice-content');

        return $message->attachments()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'source.mp3',
            'extension' => 'mp3',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => 13,
            'attachment_type' => 'voice',
        ]);
    }

    private function isSafePrivateMutation(ConversationMutationChanged $event, Conversation $conversation, string $name): bool
    {
        $payload = json_encode($event->broadcastWith());

        return $event->broadcastAs() === $name
            && $event->broadcastOn()[0] instanceof PrivateChannel
            && $event->broadcastOn()[0]->name === 'private-conversation.'.$conversation->public_uuid
            && ! str_contains($payload, 'storage/app')
            && ! str_contains($payload, 'conversation-attachments')
            && ! str_contains($payload, 'LIVEKIT')
            && ! str_contains($payload, 'token');
    }
}
