<?php

namespace Tests\Feature\Phase4B;

use App\Actions\Collaboration\ProvisionDefaultChannels;
use App\Actions\Messaging\SendMessage;
use App\Actions\People\AssignTeacherToClass;
use App\Actions\People\EndEnrollment;
use App\Actions\People\EnrollStudent;
use App\Enums\MessageType;
use App\Events\MessageReactionsChanged;
use App\Models\AcademicYear;
use App\Models\Channel;
use App\Models\GradeLevel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageReaction;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Attachments\AttachmentInspector;
use App\Services\AuditLogger;
use App\Support\MessagePayload;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class AttachmentsAndReactionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    private function context(string $role = 'Admin'): array
    {
        $academicYear = AcademicYear::query()->where('status', 'active')->first()
            ?? AcademicYear::factory()->active()->create();
        $class = SchoolClass::factory()->create(['academic_year_id' => $academicYear, 'grade_level_id' => GradeLevel::factory(), 'status' => 'active']);
        app(ProvisionDefaultChannels::class)->handle($class);
        $channel = $class->channels()->first();
        $user = User::factory()->create();
        $user->assignRole($role);
        if ($role === 'Student') {
            app(EnrollStudent::class)->handle(StudentProfile::factory()->create(['user_id' => $user]), $class, '2026-09-01');
        }

        return [$user, $class, $channel];
    }

    private function url(SchoolClass $class, Channel $channel, string $suffix = ''): string
    {
        return "/collaboration/classes/{$class->id}/channels/{$channel->id}/messages{$suffix}";
    }

    private function upload(array $files, ?string $body = null): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'body' => $body, 'attachments' => $files, 'attachment_client_uuids' => array_map(fn () => (string) Str::uuid(), $files)];
    }

    public function test_admin_and_current_student_upload_private_attachments_and_attachment_only_messages(): void
    {
        foreach (['Admin', 'Student'] as $role) {
            [$user, $class, $channel] = $this->context($role);
            $response = $this->actingAs($user)->post($this->url($class, $channel), $this->upload([UploadedFile::fake()->image('photo.jpg', 20, 20)]))->assertCreated();
            $response->assertJsonPath('message.body', null)->assertJsonPath('message.attachments.0.display_name', 'photo.jpg');
            $attachment = MessageAttachment::latest('id')->first();
            Storage::disk('local')->assertExists($attachment->path);
            $this->assertStringNotContainsString($attachment->path, $response->getContent());
            $this->assertStringNotContainsString($attachment->sha256, $response->getContent());
        }
    }

    public function test_current_class_teacher_can_upload_but_unassigned_teacher_cannot(): void
    {
        [, $class, $channel] = $this->context();
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');
        app(AssignTeacherToClass::class)->handle(TeacherProfile::factory()->create(['user_id' => $teacher]), $class, '2026-09-01');
        $this->actingAs($teacher)->post($this->url($class, $channel), $this->upload([UploadedFile::fake()->image('lesson.png')]))->assertCreated();

        $outsider = User::factory()->create();
        $outsider->assignRole('Teacher');
        $this->actingAs($outsider)->post($this->url($class, $channel), $this->upload([UploadedFile::fake()->image('private.png')]))->assertForbidden();
    }

    public function test_empty_too_many_oversized_combined_and_unsafe_uploads_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$admin, $class, $channel] = $this->context();
        $this->actingAs($admin)->withHeader('Accept', 'application/json')->post($this->url($class, $channel), ['client_uuid' => Str::uuid(), 'body' => ''])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($admin)->withHeader('Accept', 'application/json')->post($this->url($class, $channel), $this->upload(array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg"), range(1, 6))))->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->actingAs($admin)->withHeader('Accept', 'application/json')->post($this->url($class, $channel), $this->upload([UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')]))->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $this->actingAs($admin)->withHeader('Accept', 'application/json')->post($this->url($class, $channel), $this->upload([UploadedFile::fake()->create('a.pdf', 9000, 'application/pdf'), UploadedFile::fake()->create('b.pdf', 9000, 'application/pdf'), UploadedFile::fake()->create('c.pdf', 9000, 'application/pdf')]))->assertUnprocessable()->assertJsonValidationErrors('attachments');
        foreach ([UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml'), UploadedFile::fake()->create('bad.jpg', 1, 'text/plain'), UploadedFile::fake()->create('archive.zip', 1, 'application/zip')] as $file) {
            $this->actingAs($admin)->withHeader('Accept', 'application/json')->post($this->url($class, $channel), $this->upload([$file]))->assertUnprocessable()->assertJsonValidationErrors('attachments');
        }
    }

    public function test_ooxml_structure_and_filename_normalization_are_enforced(): void
    {
        [$admin, $class, $channel] = $this->context();
        $this->actingAs($admin)->withHeader('Accept', 'application/json')->post($this->url($class, $channel), $this->upload([UploadedFile::fake()->create('fake.docx', 2, 'application/zip')]))->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('_rels/.rels', '<Relationships/>');
        $zip->addFromString('word/document.xml', '<document/>');
        $zip->close();
        $file = new UploadedFile($path, "../evil\u{202E}name.docx", 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $this->actingAs($admin)->post($this->url($class, $channel), $this->upload([$file]))->assertCreated();
        $name = MessageAttachment::first()->original_name;
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringNotContainsString("\u{202E}", $name);
    }

    public function test_message_retry_is_idempotent_and_does_not_duplicate_objects(): void
    {
        [$admin, $class, $channel] = $this->context();
        $uuid = (string) Str::uuid();
        $payload = $this->upload([UploadedFile::fake()->image('one.jpg')], 'Attached');
        $payload['client_uuid'] = $uuid;
        $this->actingAs($admin)->post($this->url($class, $channel), $payload)->assertCreated();
        $retry = $this->upload([UploadedFile::fake()->image('two.jpg')], 'Changed');
        $retry['client_uuid'] = $uuid;
        $this->actingAs($admin)->post($this->url($class, $channel), $retry)->assertOk()->assertJsonPath('message.body', 'Attached');
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('message_attachments', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('message-attachments'));
    }

    public function test_database_failure_compensates_stored_objects(): void
    {
        [$admin, , $channel] = $this->context();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('Database-side failure'));

        try {
            app(SendMessage::class)->handle($channel, $admin, (string) Str::uuid(), 'Compensate', null, [
                app(AttachmentInspector::class)->inspect(UploadedFile::fake()->image('failure.jpg'), (string) Str::uuid(), 0),
            ]);
            $this->fail('Expected the transactional failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Database-side failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('message_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('message-attachments'));
    }

    public function test_orphan_reconciliation_supports_safe_dry_run_and_cleanup(): void
    {
        Storage::disk('local')->put('message-attachments/orphan', 'old');
        touch(Storage::disk('local')->path('message-attachments/orphan'), now()->subHours(2)->timestamp);

        $this->artisan('attachments:reconcile-orphans --dry-run --hours=1')->expectsOutputToContain('Would remove: message-attachments/orphan')->assertSuccessful();
        Storage::disk('local')->assertExists('message-attachments/orphan');
        $this->artisan('attachments:reconcile-orphans --hours=1')->expectsOutputToContain('Removing: message-attachments/orphan')->assertSuccessful();
        Storage::disk('local')->assertMissing('message-attachments/orphan');

        Storage::disk('local')->put('message-attachments/fresh', 'fresh');
        $this->artisan('attachments:reconcile-orphans --hours=1')->assertSuccessful();
        Storage::disk('local')->assertExists('message-attachments/fresh');
    }

    public function test_download_is_scoped_private_safe_and_hidden_access_is_explicit(): void
    {
        [$admin, $class, $channel] = $this->context();
        $message = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin]);
        $attachment = MessageAttachment::factory()->create(['message_id' => $message, 'uploaded_by' => $admin]);
        Storage::disk('local')->put($attachment->path, '%PDF-test');
        $url = $this->url($class, $channel, "/{$message->id}/attachments/{$attachment->id}");
        $this->actingAs($admin)->get($url)->assertOk()->assertHeader('x-content-type-options', 'nosniff')->assertHeader('content-disposition');
        $other = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin]);
        $this->actingAs($admin)->get($this->url($class, $channel, "/{$other->id}/attachments/{$attachment->id}"))->assertNotFound();
        $message->update(['hidden_at' => now(), 'hidden_by' => $admin->id]);
        $this->actingAs($admin)->get($url)->assertOk();
        [$student, $studentClass, $studentChannel] = $this->context('Student');
        $studentMessage = Message::factory()->create(['channel_id' => $studentChannel, 'sender_id' => $student, 'hidden_at' => now(), 'hidden_by' => $student]);
        $studentAttachment = MessageAttachment::factory()->create(['message_id' => $studentMessage, 'uploaded_by' => $student]);
        Storage::disk('local')->put($studentAttachment->path, '%PDF-test');
        $this->actingAs($student)->get($this->url($studentClass, $studentChannel, "/{$studentMessage->id}/attachments/{$studentAttachment->id}"))->assertForbidden();
    }

    public function test_hidden_tombstone_and_missing_object_do_not_leak_metadata(): void
    {
        [$admin, $class, $channel] = $this->context();
        $message = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin, 'hidden_at' => now(), 'hidden_by' => $admin]);
        $attachment = MessageAttachment::factory()->create(['message_id' => $message, 'uploaded_by' => $admin, 'original_name' => 'secret.pdf']);
        $payload = MessagePayload::make($message, $admin);
        $this->assertSame([], $payload['attachments']);
        $this->assertStringNotContainsString('secret.pdf', json_encode($payload));
        $this->actingAs($admin)->get($this->url($class, $channel, "/{$message->id}/attachments/{$attachment->id}"))->assertNotFound();
    }

    public function test_historical_student_and_archived_or_closed_scope_cannot_upload(): void
    {
        [$student, $class, $channel] = $this->context('Student');
        $enrollment = $class->enrollments()->first();
        app(EndEnrollment::class)->handle($enrollment, '2026-10-01', 'Ended');
        $this->actingAs($student)->post($this->url($class, $channel), $this->upload([UploadedFile::fake()->image('x.jpg')]))->assertForbidden();
        [$admin, $activeClass, $activeChannel] = $this->context();
        $activeChannel->update(['status' => 'archived']);
        $this->actingAs($admin)->post($this->url($activeClass, $activeChannel), $this->upload([UploadedFile::fake()->image('x.jpg')]))->assertForbidden();
        $activeChannel->update(['status' => 'active']);
        $activeClass->update(['status' => 'closed']);
        $this->actingAs($admin)->post($this->url($activeClass, $activeChannel), $this->upload([UploadedFile::fake()->image('x.jpg')]))->assertForbidden();
    }

    public function test_reactions_add_change_repeat_remove_and_aggregate_without_identities(): void
    {
        Event::fake([MessageReactionsChanged::class]);
        [$admin, $class, $channel] = $this->context();
        $message = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin]);
        $url = $this->url($class, $channel, "/{$message->id}/reaction");
        $this->actingAs($admin)->putJson($url, ['reaction' => 'like'])->assertOk()->assertJsonPath('reactions.version', 1)->assertJsonPath('reactions.current_user', 'like');
        $this->actingAs($admin)->putJson($url, ['reaction' => 'like'])->assertOk()->assertJsonPath('reactions.version', 1);
        $this->actingAs($admin)->putJson($url, ['reaction' => 'love'])->assertOk()->assertJsonPath('reactions.version', 2)->assertJsonPath('reactions.counts.love', 1);
        $this->actingAs($admin)->putJson($url, ['reaction' => '🔥'])->assertUnprocessable();
        $content = $this->actingAs($admin)->deleteJson($url)->assertOk()->assertJsonPath('reactions.version', 3)->getContent();
        $this->assertStringNotContainsString($admin->email, $content);
        $this->assertDatabaseCount('message_reactions', 0);
        Event::assertDispatchedTimes(MessageReactionsChanged::class, 3);
        $event = new MessageReactionsChanged($message->fresh());
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertArrayNotHasKey('user_id', $event->broadcastWith()['reactions']);
    }

    public function test_reactions_deny_hidden_system_historical_and_cross_scope_messages(): void
    {
        [$student, $class, $channel] = $this->context('Student');
        $message = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $student]);
        $base = $this->url($class, $channel, "/{$message->id}/reaction");
        $message->update(['hidden_at' => now(), 'hidden_by' => $student->id]);
        $this->actingAs($student)->putJson($base, ['reaction' => 'like'])->assertForbidden();
        $system = Message::factory()->create(['channel_id' => $channel, 'sender_id' => null, 'client_uuid' => null, 'type' => MessageType::System]);
        $this->actingAs($student)->putJson($this->url($class, $channel, "/{$system->id}/reaction"), ['reaction' => 'like'])->assertForbidden();
        app(EndEnrollment::class)->handle($class->enrollments()->first(), '2026-10-01', 'Ended');
        $normal = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $student]);
        $this->actingAs($student)->putJson($this->url($class, $channel, "/{$normal->id}/reaction"), ['reaction' => 'like'])->assertForbidden();
        [$admin, $otherClass, $otherChannel] = $this->context();
        $this->actingAs($admin)->putJson($this->url($otherClass, $otherChannel, "/{$normal->id}/reaction"), ['reaction' => 'like'])->assertNotFound();
    }

    public function test_reaction_unique_constraint_and_catalog_are_database_protected_by_domain(): void
    {
        [$admin, , $channel] = $this->context();
        $message = Message::factory()->create(['channel_id' => $channel, 'sender_id' => $admin]);
        MessageReaction::factory()->create(['message_id' => $message, 'user_id' => $admin, 'reaction' => 'like']);
        $this->expectException(QueryException::class);
        MessageReaction::factory()->create(['message_id' => $message, 'user_id' => $admin, 'reaction' => 'love']);
    }
}
