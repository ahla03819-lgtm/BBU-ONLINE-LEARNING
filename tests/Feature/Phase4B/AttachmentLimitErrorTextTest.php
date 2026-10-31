<?php

namespace Tests\Feature\Phase4B;

use App\Actions\Collaboration\ProvisionDefaultChannels;
use App\Models\AcademicYear;
use App\Models\Channel;
use App\Models\SchoolClass;
use App\Models\User;
use App\Actions\People\AssignTeacherToClass;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies that the attachment-limit error messages reported to the client are
 * accurate and do not contradict the actual configured limits.
 */
class AttachmentLimitErrorTextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function context(): array
    {
        $academicYear = AcademicYear::query()->where('status', 'active')->first()
            ?? AcademicYear::factory()->active()->create();
        $class = SchoolClass::factory()->create(['academic_year_id' => $academicYear, 'status' => 'active']);
        app(ProvisionDefaultChannels::class)->handle($class);
        $channel = $class->channels()->first();
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        return [$admin, $class, $channel];
    }

    private function url(SchoolClass $class, Channel $channel): string
    {
        return "/collaboration/classes/{$class->id}/channels/{$channel->id}/messages";
    }

    private function upload(array $files, ?string $body = null): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'body' => $body, 'attachments' => $files, 'attachment_client_uuids' => array_map(fn () => (string) Str::uuid(), $files)];
    }

    public function test_combined_attachment_error_reports_the_real_25_mb_cap(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$admin, $class, $channel] = $this->context();

        // Three 9 MB PDFs = 27 MB, over the configured 25 MB combined cap.
        $response = $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post($this->url($class, $channel), $this->upload([
                UploadedFile::fake()->create('a.pdf', 9000, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 9000, 'application/pdf'),
                UploadedFile::fake()->create('c.pdf', 9000, 'application/pdf'),
            ]))
            ->assertUnprocessable();

        $errors = $response->json('errors.attachments');
        $this->assertIsArray($errors);
        $message = implode(' ', (array) $errors);

        // The message must name the actual configured combined cap, not 500 MB.
        $this->assertStringContainsString('25 MB', $message);
        $this->assertStringNotContainsString('500 MB', $message);
    }

    public function test_single_attachment_error_reports_the_real_10_mb_cap(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$admin, $class, $channel] = $this->context();

        $response = $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post($this->url($class, $channel), $this->upload([
                UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'),
            ]))
            ->assertUnprocessable();

        $content = $response->getContent();

        // The per-file rule is 10240 KB (10 MB). The message must not claim 500 MB.
        $this->assertStringContainsString('10240 kilobytes', $content);
        $this->assertStringNotContainsString('500 MB', $content);
    }

    public function test_five_files_is_the_real_file_count_cap(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$admin, $class, $channel] = $this->context();

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post($this->url($class, $channel), $this->upload(array_map(
                fn ($i) => UploadedFile::fake()->create("{$i}.pdf", 100, 'application/pdf'),
                range(1, 6),
            )))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments');
    }
}