<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Class-cover mutations must be traceable through the existing audit_logs system.
 *
 * This covers the gap that made an unexplained cover upload on a real class
 * impossible to attribute: updateCover()/destroyCover() wrote no audit row, so
 * investigation required filesystem and Inertia-payload forensics.
 *
 * Success events must only appear once the stored file and the row are both in
 * place, and an authorization failure must never leave a success event behind.
 */
class ClassCoverAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create(['status' => \App\Enums\SchoolClassStatus::Active]);
    }

    public function test_authorized_upload_writes_one_audit_record(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');

        $this->actingAs($admin)->post("/classes/{$class->id}/cover", [
            'cover' => UploadedFile::fake()->image('cover.jpg', 40, 40),
        ])->assertRedirect();

        $path = $class->fresh()->cover_image_path;
        $this->assertNotNull($path);

        $logs = AuditLog::query()->where('action', 'school-class.cover.uploaded')->get();
        $this->assertCount(1, $logs, 'exactly one upload audit record');

        $log = $logs->first();
        $this->assertSame($admin->id, $log->actor_id, 'the acting user is recorded');
        $this->assertSame(SchoolClass::class, $log->target_type);
        $this->assertSame($class->id, (int) $log->target_id);
        $this->assertNull($log->before['cover_image_path'], 'no previous path on a first upload');
        $this->assertSame($path, $log->after['cover_image_path'], 'the stored managed path is recorded');
    }

    public function test_authorized_replacement_records_both_old_and_new_paths(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');

        $first = "class-covers/{$class->id}/first-cover.jpg";
        Storage::disk('public')->put($first, 'original');
        $class->update(['cover_image_path' => $first]);

        $this->actingAs($admin)->post("/classes/{$class->id}/cover", [
            'cover' => UploadedFile::fake()->image('replacement.jpg', 40, 40),
        ])->assertRedirect();

        $second = $class->fresh()->cover_image_path;
        $this->assertNotSame($first, $second);

        $log = AuditLog::query()->where('action', 'school-class.cover.uploaded')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($first, $log->before['cover_image_path'], 'replacement records the old managed path');
        $this->assertSame($second, $log->after['cover_image_path'], 'replacement records the new managed path');

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_authorized_removal_writes_one_audit_record_and_deletes_the_file(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');
        $path = "class-covers/{$class->id}/doomed.jpg";
        Storage::disk('public')->put($path, 'bytes');
        $class->update(['cover_image_path' => $path]);

        $this->actingAs($admin)->delete("/classes/{$class->id}/cover")->assertRedirect();

        $this->assertNull($class->fresh()->cover_image_path);
        Storage::disk('public')->assertMissing($path);

        $logs = AuditLog::query()->where('action', 'school-class.cover.removed')->get();
        $this->assertCount(1, $logs);
        $this->assertSame($admin->id, $logs->first()->actor_id);
        $this->assertSame($path, $logs->first()->before['cover_image_path'], 'the removed managed path is recorded');
        $this->assertNull($logs->first()->after['cover_image_path']);
    }

    public function test_denied_student_upload_writes_no_success_audit_record(): void
    {
        $class = $this->activeClass();

        $this->actingAs($this->user('Student'))->post("/classes/{$class->id}/cover", [
            'cover' => UploadedFile::fake()->image('cover.jpg', 40, 40),
        ])->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'school-class.cover.%')->count());
        $this->assertNull($class->fresh()->cover_image_path);
    }

    public function test_denied_student_removal_writes_no_success_audit_record(): void
    {
        $class = $this->activeClass();
        $path = "class-covers/{$class->id}/kept.jpg";
        Storage::disk('public')->put($path, 'bytes');
        $class->update(['cover_image_path' => $path]);

        $this->actingAs($this->user('Student'))->delete("/classes/{$class->id}/cover")->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'school-class.cover.%')->count());
        $this->assertSame($path, $class->fresh()->cover_image_path, 'a denied removal changes nothing');
        Storage::disk('public')->assertExists($path);
    }

    public function test_failed_upload_mutation_writes_no_success_audit_record(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');

        SchoolClass::updating(fn () => throw new \RuntimeException('Simulated database failure'));

        try {
            $this->actingAs($admin)->post("/classes/{$class->id}/cover", [
                'cover' => UploadedFile::fake()->image('cover.jpg', 40, 40),
            ]);
        } catch (\Throwable) {
            // The controller rethrows after cleaning up the stored file.
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'school-class.cover.%')->count(),
            'a mutation that never completed must not leave a success audit record');
        $this->assertNull($class->fresh()->cover_image_path);
        $this->assertSame([], Storage::disk('public')->allFiles("class-covers/{$class->id}"),
            'the orphaned stored file was cleaned up');
    }

    public function test_audit_records_never_contain_sensitive_material(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');

        $this->actingAs($admin)->post("/classes/{$class->id}/cover", [
            'cover' => UploadedFile::fake()->image('cover.jpg', 40, 40),
        ])->assertRedirect();

        $log = AuditLog::query()->where('action', 'school-class.cover.uploaded')->firstOrFail();
        $encoded = json_encode([$log->before, $log->after]);

        foreach (['password', '_token', 'csrf', 'XSRF', 'cookie', 'session', 'remember_token'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $encoded);
        }
        // Only the managed path is captured - never the bytes themselves.
        $this->assertStringNotContainsString(base64_encode('fake'), $encoded);
    }
}