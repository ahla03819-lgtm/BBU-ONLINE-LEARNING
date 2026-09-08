<?php

namespace Tests\Feature\Phase16;

use App\Events\ConversationMessageSent;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationRichMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_the_supported_type_matrix_is_accepted_and_metadata_is_safe(): void
    {
        [$conversation, $sender] = $this->conversation();
        $types = [
            ['photo.jpg', 'image/jpeg'], ['photo.jpeg', 'image/jpeg'], ['photo.png', 'image/png'], ['photo.webp', 'image/webp'],
            ['voice.mp3', 'audio/mpeg'], ['voice.m4a', 'audio/mp4'], ['voice.ogg', 'audio/ogg'], ['voice.webm', 'audio/webm'],
            ['movie.mp4', 'video/mp4'], ['movie.webm', 'video/webm'], ['notes.pdf', 'application/pdf'], ['notes.doc', 'application/msword'],
            ['notes.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], ['scores.xls', 'application/vnd.ms-excel'], ['scores.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], ['slides.ppt', 'application/vnd.ms-powerpoint'], ['slides.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'], ['notes.txt', 'text/plain'], ['scores.csv', 'text/csv'],
        ];
        foreach (array_chunk($types, 5) as $chunk) {
            $this->actingAs($sender)->post(route('conversations.messages.store', $conversation), ['body' => '', 'attachments' => array_map(fn ($type) => UploadedFile::fake()->create($type[0], 12, $type[1]), $chunk)])->assertCreated();
        }
        $this->assertDatabaseCount('conversation_message_attachments', count($types));
        $this->assertDatabaseMissing('conversation_message_attachments', ['path' => '/storage/anything']);
    }

    public function test_text_attachment_and_attachment_only_messages_preserve_their_intended_body_contract(): void
    {
        [$conversation, $sender] = $this->conversation();

        $this->actingAs($sender)->postJson(route('conversations.messages.store', $conversation), ['body' => 'Text only'])->assertCreated();
        $attachmentOnly = $this->actingAs($sender)->post(route('conversations.messages.store', $conversation), [
            'attachments' => [UploadedFile::fake()->create('photo.png', 12, 'image/png')],
        ])->assertCreated();
        $document = $this->actingAs($sender)->post(route('conversations.messages.store', $conversation), [
            'body' => '  Review this file  ',
            'attachments' => [UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf')],
        ])->assertCreated();
        $this->actingAs($sender)->post(route('conversations.messages.store', $conversation), [
            'body' => " \n\t ",
            'attachments' => [UploadedFile::fake()->create('voice.ogg', 12, 'audio/ogg')],
        ])->assertCreated();

        $messages = $conversation->messages()->with('attachments')->orderBy('id')->get();
        $this->assertSame('Text only', $messages[0]->body);
        $this->assertNull($messages[1]->body);
        $this->assertSame('Review this file', $messages[2]->body);
        $this->assertNull($messages[3]->body);
        $this->assertCount(1, $messages[1]->attachments);
        $this->assertCount(1, $messages[2]->attachments);
        $this->assertCount(1, $messages[3]->attachments);
        $attachmentOnly->assertJsonPath('message.body', null);
        $document->assertJsonPath('message.body', 'Review this file')
            ->assertJsonPath('message.attachments.0.name', 'notes.pdf')
            ->assertJsonPath('message.attachments.0.size_bytes', 12288);
        $this->assertArrayNotHasKey('path', $document->json('message.attachments.0'));
    }

    public function test_browser_voice_recordings_and_uploaded_webm_video_keep_distinct_classifications(): void
    {
        [$conversation, $sender] = $this->conversation();

        foreach ([
            ['voice-message-1788456527150.webm', 'audio/webm', 'voice'],
            ['voice-message-1788456527151.webm', 'video/webm', 'voice'],
            ['voice-message-1788456527152.webm', 'video/webm;codecs=opus', 'voice'],
            ['lecture-recording.webm', 'video/webm', 'video'],
        ] as [$name, $mime, $type]) {
            $response = $this->actingAs($sender)->post(route('conversations.messages.store', $conversation), [
                'attachments' => [UploadedFile::fake()->create($name, 12, $mime)],
            ])->assertCreated()->assertJsonPath('message.attachments.0.type', $type);

            $this->assertNull($response->json('message.body'));
            $this->assertArrayNotHasKey('path', $response->json('message.attachments.0'));
        }

        $this->assertDatabaseHas('conversation_message_attachments', [
            'attachment_type' => 'voice',
            'mime_type' => 'video/webm',
        ]);
        $this->assertDatabaseHas('conversation_message_attachments', [
            'attachment_type' => 'video',
            'original_name' => 'lecture-recording.webm',
        ]);
    }

    public function test_private_voice_delivery_is_inline_and_keeps_the_safe_media_contract(): void
    {
        [$conversation, $sender, $member] = $this->conversation();
        Event::fake([ConversationMessageSent::class]);

        $response = $this->actingAs($sender)->post(route('conversations.messages.store', $conversation), [
            'attachments' => [UploadedFile::fake()->create('voice-message-1788456527153.webm', 12, 'video/webm;codecs=opus')],
        ])->assertCreated()->assertJsonPath('message.body', null)->assertJsonPath('message.attachments.0.type', 'voice');

        $attachment = $response->json('message.attachments.0');
        $this->assertArrayNotHasKey('path', $attachment);
        $this->assertStringNotContainsString('conversation-attachments/', $attachment['url']);
        $this->actingAs($member)->get($attachment['url'])->assertOk()->assertHeader('Content-Type', 'video/webm')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control');
        Event::assertDispatched(ConversationMessageSent::class, fn ($event) => $event->broadcastWith()['message']['attachments'][0]['type'] === 'voice'
            && ! array_key_exists('path', $event->broadcastWith()['message']['attachments'][0]));
    }

    public function test_empty_messages_are_rejected_and_attachment_only_realtime_payloads_are_safe(): void
    {
        [$conversation, $sender] = $this->conversation();
        Event::fake([ConversationMessageSent::class]);

        $this->actingAs($sender)->postJson(route('conversations.messages.store', $conversation), [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($sender)->postJson(route('conversations.messages.store', $conversation), ['body' => "  \t  "])->assertUnprocessable()->assertJsonValidationErrors('body');
        $response = $this->actingAs($sender)->post(route('conversations.messages.store', $conversation), [
            'attachments' => [UploadedFile::fake()->create('movie.mp4', 12, 'video/mp4')],
        ])->assertCreated();

        $this->assertNull($response->json('message.body'));
        Event::assertDispatched(ConversationMessageSent::class, fn ($event) => $event->broadcastWith()['message']['body'] === null
            && $event->broadcastWith()['message']['attachments'][0]['type'] === 'video'
            && ! array_key_exists('path', $event->broadcastWith()['message']['attachments'][0]));
    }

    public function test_rollback_refuses_to_make_message_bodies_required_when_attachment_only_messages_exist(): void
    {
        [$conversation, $sender] = $this->conversation();
        $conversation->messages()->create(['sender_user_id' => $sender->id, 'body' => null]);
        $migration = require database_path('migrations/2026_09_03_000000_make_conversation_messages_body_nullable.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rollback cannot restore a required conversation message body');
        $migration->down();
    }

    public function test_dangerous_or_misleading_files_and_excess_counts_are_rejected_without_records(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$conversation, $sender] = $this->conversation();
        foreach (['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh', 'html', 'htm', 'js', 'svg'] as $extension) {
            $this->actingAs($sender)->withHeaders(['Accept' => 'application/json'])->post(route('conversations.messages.store', $conversation), ['attachments' => [UploadedFile::fake()->create("malware.{$extension}", 1, 'text/plain')]])->assertStatus(422);
        }
        $this->actingAs($sender)->post(route('conversations.messages.store', $conversation), ['attachments' => [UploadedFile::fake()->create('looks-like-photo.jpg', 1, 'text/plain')]])->assertStatus(422);
        $this->actingAs($sender)->withHeaders(['Accept' => 'application/json'])->post(route('conversations.messages.store', $conversation), ['attachments' => array_fill(0, 6, UploadedFile::fake()->create('photo.jpg', 1, 'image/jpeg'))])->assertStatus(422);
        $this->assertDatabaseCount('conversation_messages', 0);
        $this->assertDatabaseCount('conversation_message_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_size_limits_are_enforced_before_private_storage(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$conversation, $sender] = $this->conversation();
        foreach ([['large.jpg', 10241, 'image/jpeg'], ['large.pdf', 512001, 'application/pdf'], ['large.mp4', 102401, 'video/mp4']] as [$name, $kilobytes, $mime]) {
            $this->actingAs($sender)->withHeaders(['Accept' => 'application/json'])->post(route('conversations.messages.store', $conversation), ['attachments' => [UploadedFile::fake()->create($name, $kilobytes, $mime)]])->assertStatus(422);
        }
        $this->assertDatabaseCount('conversation_messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_supported_documents_allow_exactly_500_megabytes_but_not_one_kilobyte_more(): void
    {
        [$conversation, $sender] = $this->conversation();

        foreach ([
            ['large.pdf', 'application/pdf'],
            ['large.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['large.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['large.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        ] as [$name, $mime]) {
            $this->actingAs($sender)->post(route('conversations.messages.store', $conversation), [
                'attachments' => [UploadedFile::fake()->create($name, 512000, $mime)],
            ])->assertCreated();
        }

        $this->actingAs($sender)->withHeaders(['Accept' => 'application/json'])->post(route('conversations.messages.store', $conversation), [
            'attachments' => [UploadedFile::fake()->create('too-large.pdf', 512001, 'application/pdf')],
        ])->assertUnprocessable();
    }

    public function test_private_attachment_delivery_and_realtime_payload_are_member_scoped(): void
    {
        [$conversation, $sender, $member] = $this->conversation();
        Event::fake([ConversationMessageSent::class]);
        $response = $this->actingAs($sender)->post(route('conversations.messages.store', $conversation), ['attachments' => [UploadedFile::fake()->create('photo.jpg', 12, 'image/jpeg')]])->assertCreated();
        $payload = $response->json('message');
        $this->assertNull($payload['body']);
        $this->assertArrayNotHasKey('path', $payload['attachments'][0]);
        $this->assertStringNotContainsString('conversation-attachments/', $payload['attachments'][0]['url']);
        $this->actingAs($member)->get($payload['attachments'][0]['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control');
        Event::assertDispatched(ConversationMessageSent::class, fn ($event) => $event->broadcastOn()[0] instanceof PrivateChannel && $event->broadcastWith()['message']['attachments'][0]['uuid'] === $payload['attachments'][0]['uuid'] && ! array_key_exists('path', $event->broadcastWith()['message']['attachments'][0]));
    }

    public function test_guests_removed_members_unrelated_users_and_super_admin_cannot_access_attachments_or_cross_conversation_records(): void
    {
        [$conversation, $sender, $member] = $this->conversation();
        $message = $conversation->messages()->create(['sender_user_id' => $sender->id, 'body' => 'private']);
        $attachment = $message->attachments()->create(['disk' => 'local', 'path' => 'conversation-attachments/private-object', 'original_name' => 'private.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 12, 'attachment_type' => 'file']);
        Storage::disk('local')->put($attachment->path, 'private');
        $url = route('conversations.attachments.show', [$conversation, $attachment]);
        $this->get($url)->assertRedirect('/login');
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get($url)->assertForbidden();
        $super = User::factory()->create();
        $super->assignRole('Super Admin');
        $this->actingAs($super)->get($url)->assertForbidden();
        ConversationMember::where('conversation_id', $conversation->id)->where('user_id', $member->id)->update(['left_at' => now()]);
        $this->actingAs($member)->get($url)->assertForbidden();
        [$other] = $this->conversation();
        $attachmentUuid = basename(parse_url($url, PHP_URL_PATH));
        $this->actingAs($sender)->get("/conversations/{$other->public_uuid}/attachments/{$attachmentUuid}")->assertForbidden();
    }

    private function conversation(): array
    {
        $sender = User::factory()->create();
        $member = User::factory()->create();
        $conversation = Conversation::factory()->direct()->create(['direct_pair_key' => collect([$sender->id, $member->id])->sort()->implode(':')]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $member->id]);

        return [$conversation, $sender, $member];
    }
}
