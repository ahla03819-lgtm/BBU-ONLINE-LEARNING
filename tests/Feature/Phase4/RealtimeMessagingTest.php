<?php

namespace Tests\Feature\Phase4;

use App\Actions\Collaboration\ProvisionDefaultChannels;
use App\Actions\Messaging\CreateSystemMessage;
use App\Actions\Messaging\SendMessage;
use App\Actions\People\AssignTeacherToClass;
use App\Actions\People\AssignTeacherToClassSubject;
use App\Actions\People\EndEnrollment;
use App\Actions\People\EnrollStudent;
use App\Enums\ChannelStatus;
use App\Enums\MessageType;
use App\Enums\SchoolClassStatus;
use App\Events\MessageSent;
use App\Models\AcademicYear;
use App\Models\Channel;
use App\Models\ChannelReadState;
use App\Models\ClassSubject;
use App\Models\GradeLevel;
use App\Models\Message;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\MessagePayload;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealtimeMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'grade_level_id' => GradeLevel::factory(), 'status' => SchoolClassStatus::Active]);
    }

    private function adminContext(): array
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $class = $this->activeClass();
        app(ProvisionDefaultChannels::class)->handle($class);

        return [$user, $class, $class->channels()->first()];
    }

    private function endpoint(SchoolClass $class, Channel $channel, string $suffix = ''): string
    {
        return "/collaboration/classes/{$class->id}/channels/{$channel->id}/messages{$suffix}";
    }

    public function test_authorized_send_is_plain_text_idempotent_and_emits_safe_event(): void
    {
        Event::fake([MessageSent::class]);
        [$admin, $class, $channel] = $this->adminContext();
        $uuid = (string) Str::uuid();
        $payload = ['client_uuid' => $uuid, 'body' => "<script>alert(1)</script>\r\nHello", 'type' => 'system'];
        $this->actingAs($admin)->postJson($this->endpoint($class, $channel), $payload)->assertCreated()->assertJsonPath('message.type', 'text')->assertJsonPath('message.body', "<script>alert(1)</script>\nHello");
        $this->actingAs($admin)->postJson($this->endpoint($class, $channel), $payload)->assertOk();
        $this->assertDatabaseCount('messages', 1);
        Event::assertDispatchedTimes(MessageSent::class, 1);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new MessageSent(Message::first()));
        $this->actingAs($admin)->postJson($this->endpoint($class, $channel), ['client_uuid' => Str::uuid(), 'body' => '   '])->assertUnprocessable();
        $this->actingAs($admin)->postJson($this->endpoint($class, $channel), ['client_uuid' => Str::uuid(), 'body' => str_repeat('a', 4001)])->assertUnprocessable();
    }

    public function test_replies_are_flat_scoped_and_hidden_parents_are_tombstones(): void
    {
        [$admin, $class, $channel] = $this->adminContext();
        $other = Channel::factory()->create(['school_class_id' => $class]);
        $parent = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin]);
        $foreign = Message::factory()->create(['channel_id' => $other, 'sender_id' => $admin]);
        $this->actingAs($admin)->postJson($this->endpoint($class, $channel), ['client_uuid' => Str::uuid(), 'body' => 'Reply', 'reply_to_id' => $foreign->id])->assertUnprocessable();
        $parent->update(['hidden_at' => now(), 'hidden_by' => $admin->id, 'hidden_reason' => 'Retained secret']);
        $reply = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin, 'reply_to_id' => $parent]);
        $this->actingAs($admin)->getJson($this->endpoint($class, $channel))->assertOk()->assertJsonPath('messages.1.reply_to.id', $parent->id)->assertJsonPath('messages.1.reply_to.body', null);
        $this->assertNull(MessagePayload::make($parent)['body']);
        $this->assertSame('Retained secret', $parent->fresh()->hidden_reason);
        $this->assertSame($parent->id, $reply->reply_to_id);
    }

    public function test_current_student_can_message_but_historical_and_closed_relationships_cannot(): void
    {
        $class = $this->activeClass();
        app(ProvisionDefaultChannels::class)->handle($class);
        $channel = $class->channels()->first();
        $user = User::factory()->create();
        $user->assignRole('Student');
        $student = StudentProfile::factory()->create(['user_id' => $user]);
        $enrollment = app(EnrollStudent::class)->handle($student, $class, '2026-09-01');
        $this->actingAs($user)->postJson($this->endpoint($class, $channel), ['client_uuid' => Str::uuid(), 'body' => 'Current'])->assertCreated();
        app(EndEnrollment::class)->handle($enrollment, '2026-10-01', 'Ended');
        $this->actingAs($user)->getJson($this->endpoint($class, $channel))->assertForbidden();
        $class->update(['status' => SchoolClassStatus::Closed]);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin)->getJson($this->endpoint($class, $channel))->assertOk();
        $this->actingAs($admin)->postJson($this->endpoint($class, $channel), ['client_uuid' => Str::uuid(), 'body' => 'Closed'])->assertForbidden();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $this->actingAs($superAdmin)->getJson($this->endpoint($class, $channel))->assertOk();
        $this->actingAs($superAdmin)->postJson($this->endpoint($class, $channel), ['client_uuid' => Str::uuid(), 'body' => 'Still closed'])->assertForbidden();
    }

    public function test_archived_channel_and_missing_permission_are_denied(): void
    {
        [$admin, $class, $channel] = $this->adminContext();
        $channel->update(['status' => ChannelStatus::Archived]);
        $this->actingAs($admin)->postJson($this->endpoint($class, $channel), ['client_uuid' => Str::uuid(), 'body' => 'No'])->assertForbidden();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson($this->endpoint($class, $channel))->assertForbidden();
    }

    public function test_edit_and_self_hide_use_exact_fifteen_minute_boundary(): void
    {
        [$admin, $class, $channel] = $this->adminContext();
        Carbon::setTestNow('2026-09-01 12:00:00');
        $editable = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin, 'created_at' => now()->subMinutes(15)]);
        $this->actingAs($admin)->patchJson($this->endpoint($class, $channel, "/{$editable->id}"), ['body' => 'Edited'])->assertOk()->assertJsonPath('message.body', 'Edited');
        $hideable = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin, 'created_at' => now()->subMinutes(15)]);
        $this->actingAs($admin)->patchJson($this->endpoint($class, $channel, "/{$hideable->id}/hide"))->assertOk()->assertJsonPath('message.body', null);
        $expired = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin, 'created_at' => now()->subMinutes(15)->subSecond()]);
        $this->actingAs($admin)->patchJson($this->endpoint($class, $channel, "/{$expired->id}"), ['body' => 'Late'])->assertForbidden();
        $system = Message::factory()->create(['channel_id' => $channel, 'sender_id' => null, 'client_uuid' => null, 'type' => MessageType::System]);
        $this->actingAs($admin)->patchJson($this->endpoint($class, $channel, "/{$system->id}"), ['body' => 'No'])->assertForbidden();
        Carbon::setTestNow();
    }

    public function test_class_teacher_moderates_but_subject_only_teacher_cannot(): void
    {
        $class = $this->activeClass();
        app(ProvisionDefaultChannels::class)->handle($class);
        $channel = $class->channels()->first();
        $author = User::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $author]);
        $classUser = User::factory()->create();
        $classUser->assignRole('Teacher');
        app(AssignTeacherToClass::class)->handle(TeacherProfile::factory()->create(['user_id' => $classUser]), $class, '2026-09-01');
        $this->actingAs($classUser)->patchJson($this->endpoint($class, $channel, "/{$message->id}/moderate"), ['reason' => 'Class safety'])->assertOk();

        $subjectUser = User::factory()->create();
        $subjectUser->assignRole('Teacher');
        $classSubject = ClassSubject::factory()->create(['school_class_id' => $class, 'subject_id' => Subject::factory(), 'status' => 'active']);
        app(AssignTeacherToClassSubject::class)->handle(TeacherProfile::factory()->create(['user_id' => $subjectUser]), $classSubject, '2026-09-01');
        $another = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $author]);
        $this->actingAs($subjectUser)->patchJson($this->endpoint($class, $channel, "/{$another->id}/moderate"), ['reason' => 'No'])->assertForbidden();
    }

    public function test_read_cursor_is_private_monotonic_and_channel_scoped(): void
    {
        [$admin, $class, $channel] = $this->adminContext();
        $first = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin]);
        $second = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin]);
        $url = "/collaboration/classes/{$class->id}/channels/{$channel->id}/read-state";
        $this->actingAs($admin)->putJson($url, ['message_id' => $second->id])->assertOk()->assertJsonPath('last_read_message_id', $second->id);
        $this->actingAs($admin)->putJson($url, ['message_id' => $first->id])->assertOk()->assertJsonPath('last_read_message_id', $second->id);
        $foreignChannel = Channel::factory()->create(['school_class_id' => $class]);
        $foreign = Message::factory()->create(['channel_id' => $foreignChannel, 'sender_id' => $admin]);
        $this->actingAs($admin)->putJson($url, ['message_id' => $foreign->id])->assertUnprocessable();
        $this->assertDatabaseCount('channel_read_states', 1);
        $this->assertSame($admin->id, ChannelReadState::first()->user_id);
    }

    public function test_cursor_pagination_and_after_id_recovery_are_stable(): void
    {
        [$admin, $class, $channel] = $this->adminContext();
        Message::factory()->count(55)->create(['channel_id' => $channel, 'sender_id' => $admin]);
        $response = $this->actingAs($admin)->getJson($this->endpoint($class, $channel))->assertOk()->assertJsonCount(50, 'messages')->assertJsonPath('has_more', true);
        $messages = $response->json('messages');
        $this->assertLessThan($messages[49]['id'], $messages[0]['id']);
        $this->actingAs($admin)->getJson($this->endpoint($class, $channel).'?before_id='.$messages[0]['id'])->assertOk()->assertJsonCount(5, 'messages');
        $this->actingAs($admin)->getJson($this->endpoint($class, $channel).'?after_id='.$messages[49]['id'])->assertOk()->assertJsonCount(0, 'messages');
        $this->actingAs($admin)->getJson($this->endpoint($class, $channel).'?before_id=5&after_id=2')->assertUnprocessable();
        $new = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin]);
        $this->actingAs($admin)->getJson($this->endpoint($class, $channel).'?after_id='.$messages[49]['id'])->assertOk()->assertJsonPath('messages.0.id', $new->id);
    }

    public function test_cross_class_and_cross_channel_message_ids_are_not_exposed(): void
    {
        [$admin, $class, $channel] = $this->adminContext();
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'grade_level_id' => GradeLevel::factory(), 'status' => SchoolClassStatus::Active]);
        $otherChannel = Channel::factory()->create(['school_class_id' => $otherClass]);
        $message = Message::factory()->create(['channel_id' => $otherChannel, 'sender_id' => $admin]);
        $this->actingAs($admin)->patchJson($this->endpoint($class, $channel, "/{$message->id}"), ['body' => 'IDOR'])->assertNotFound();
        $this->actingAs($admin)->getJson($this->endpoint($class, $otherChannel))->assertNotFound();
    }

    public function test_send_action_is_idempotent_and_database_constraint_exists(): void
    {
        [$admin, , $channel] = $this->adminContext();
        $uuid = (string) Str::uuid();
        $first = app(SendMessage::class)->handle($channel, $admin, $uuid, 'Once');
        $second = app(SendMessage::class)->handle($channel, $admin, $uuid, 'Twice');
        $this->assertTrue($first->is($second));
        $this->assertSame('Once', $second->body);
        $this->expectException(QueryException::class);
        Message::query()->create(['channel_id' => $channel->id, 'sender_id' => $admin->id, 'client_uuid' => $uuid, 'type' => MessageType::Text, 'body' => 'Duplicate']);
    }

    public function test_system_messages_can_only_be_created_by_server_action(): void
    {
        [$admin, $class, $channel] = $this->adminContext();
        $system = app(CreateSystemMessage::class)->handle($channel, 'Class lifecycle updated.');
        $this->assertTrue($system->isSystem());
        $this->assertNull($system->sender_id);
        $this->assertNull($system->client_uuid);
        $this->actingAs($admin)->postJson($this->endpoint($class, $channel), ['client_uuid' => Str::uuid(), 'body' => 'Pretend', 'type' => 'system'])->assertCreated()->assertJsonPath('message.type', 'text');
    }

    public function test_message_creation_is_rate_limited_server_side(): void
    {
        [$admin, $class, $channel] = $this->adminContext();
        foreach (range(1, 5) as $number) {
            $this->actingAs($admin)->postJson($this->endpoint($class, $channel), ['client_uuid' => Str::uuid(), 'body' => "Message {$number}"])->assertCreated();
        }
        $this->actingAs($admin)->postJson($this->endpoint($class, $channel), ['client_uuid' => Str::uuid(), 'body' => 'Limited'])->assertTooManyRequests();
    }
}
