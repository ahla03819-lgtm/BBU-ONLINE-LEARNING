<?php

namespace Tests\Feature;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\SchoolClass;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Custom channel image mutations must be traceable through the existing
 * audit_logs system, mirroring the class-cover convention.
 *
 * The denial contract matters as much as the success path: the BUG-09 domain
 * guard and ChannelPolicy::update both run before any audit call, so a rejected
 * attempt must never leave a success event behind.
 */
class ChannelImageAuditTest extends TestCase
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

    private function channel(ChannelType $type): Channel
    {
        return Channel::factory()->create([
            'school_class_id' => SchoolClass::factory()->create()->id,
            'type' => $type,
            'status' => ChannelStatus::Active,
        ]);
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->image('channel.png', 40, 40)->mimeType('image/png');
    }

    private function uploadUrl(Channel $channel): string
    {
        return "/collaboration/classes/{$channel->school_class_id}/channels/{$channel->id}/image";
    }

    private function coverAudits(): \Illuminate\Database\Eloquent\Collection
    {
        return AuditLog::query()->where('action', 'like', 'channel.image.%')->get();
    }

    public function test_authorized_custom_upload_writes_exactly_one_audit_record(): void
    {
        $channel = $this->channel(ChannelType::Custom);
        $admin = $this->user('Super Admin');

        $this->actingAs($admin)->post($this->uploadUrl($channel), ['image' => $this->png()])->assertRedirect();

        $path = $channel->fresh()->image_path;
        $this->assertNotNull($path);
        $this->assertCount(1, $this->coverAudits(), 'exactly one upload audit record');

        $log = $this->coverAudits()->first();
        $this->assertSame('channel.image.uploaded', $log->action);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(Channel::class, $log->target_type);
        $this->assertSame($channel->id, (int) $log->target_id);
        $this->assertNull($log->before['image_path'], 'no previous path on a first upload');
        $this->assertSame($path, $log->after['image_path']);
    }

    public function test_replacement_records_old_and_new_managed_paths(): void
    {
        $channel = $this->channel(ChannelType::Custom);
        $admin = $this->user('Super Admin');
        $first = "channel-images/{$channel->id}/first.png";
        Storage::disk('public')->put($first, 'original');
        $channel->update(['image_path' => $first]);

        $this->actingAs($admin)->post($this->uploadUrl($channel), ['image' => $this->png()])->assertRedirect();

        $second = $channel->fresh()->image_path;
        $this->assertNotSame($first, $second);

        $log = AuditLog::query()->where('action', 'channel.image.uploaded')->orderByDesc('id')->first();
        $this->assertSame('channel.image.uploaded', $log->action);
        $this->assertSame($first, $log->before['image_path'], 'replacement records the old managed path');
        $this->assertSame($second, $log->after['image_path'], 'replacement records the new managed path');

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_authorized_remove_records_old_path_and_null(): void
    {
        $channel = $this->channel(ChannelType::Custom);
        $admin = $this->user('Super Admin');
        $path = "channel-images/{$channel->id}/doomed.png";
        Storage::disk('public')->put($path, 'bytes');
        $channel->update(['image_path' => $path]);

        $this->actingAs($admin)->delete($this->uploadUrl($channel))->assertRedirect();

        $this->assertNull($channel->fresh()->image_path);
        Storage::disk('public')->assertMissing($path);

        $this->assertCount(1, $this->coverAudits());
        $log = $this->coverAudits()->first();
        $this->assertSame('channel.image.removed', $log->action);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($path, $log->before['image_path']);
        $this->assertNull($log->after['image_path']);
    }

    public function test_denied_student_upload_writes_no_success_audit(): void
    {
        $channel = $this->channel(ChannelType::Custom);

        $this->actingAs($this->user('Student'))
            ->post($this->uploadUrl($channel), ['image' => $this->png()])->assertForbidden();

        $this->assertCount(0, $this->coverAudits());
        $this->assertNull($channel->fresh()->image_path);
        $this->assertSame([], Storage::disk('public')->allFiles("channel-images/{$channel->id}"));
    }

    public function test_denied_student_remove_writes_no_success_audit(): void
    {
        $channel = $this->channel(ChannelType::Custom);
        $path = "channel-images/{$channel->id}/kept.png";
        Storage::disk('public')->put($path, 'bytes');
        $channel->update(['image_path' => $path]);

        $this->actingAs($this->user('Student'))->delete($this->uploadUrl($channel))->assertForbidden();

        $this->assertCount(0, $this->coverAudits());
        $this->assertSame($path, $channel->fresh()->image_path);
        Storage::disk('public')->assertExists($path);
    }

    /**
     * @dataProvider systemChannelProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('systemChannelProvider')]
    public function test_super_admin_system_channel_upload_writes_no_success_audit(ChannelType $type): void
    {
        $channel = $this->channel($type);

        $this->actingAs($this->user('Super Admin'))
            ->post($this->uploadUrl($channel), ['image' => $this->png()])->assertForbidden();

        $this->assertCount(0, $this->coverAudits(), "a {$type->value} channel must not produce a success audit");
        $this->assertNull($channel->fresh()->image_path);
    }

    /**
     * @dataProvider systemChannelProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('systemChannelProvider')]
    public function test_super_admin_system_channel_remove_writes_no_success_audit(ChannelType $type): void
    {
        $channel = $this->channel($type);
        $existing = "channel-images/{$channel->id}/existing.png";
        Storage::disk('public')->put($existing, 'bytes');
        $channel->update(['image_path' => $existing]);

        $this->actingAs($this->user('Super Admin'))->delete($this->uploadUrl($channel))->assertForbidden();

        $this->assertCount(0, $this->coverAudits(), "a {$type->value} channel must not produce a success audit");
        $this->assertSame($existing, $channel->fresh()->image_path);
        Storage::disk('public')->assertExists($existing);
    }

    /** @return array<string, array{0: ChannelType}> */
    public static function systemChannelProvider(): array
    {
        return [
            'general' => [ChannelType::General],
            'announcement' => [ChannelType::Announcement],
            'subject' => [ChannelType::Subject],
        ];
    }

    public function test_failed_mutation_writes_no_success_audit(): void
    {
        $channel = $this->channel(ChannelType::Custom);
        $admin = $this->user('Super Admin');

        Channel::updating(fn () => throw new \RuntimeException('Simulated database failure'));

        try {
            $this->actingAs($admin)->post($this->uploadUrl($channel), ['image' => $this->png()]);
        } catch (\Throwable) {
            // The controller rethrows after cleaning up the stored file.
        }

        $this->assertCount(0, $this->coverAudits(), 'a mutation that never completed must not be audited as a success');
        $this->assertNull($channel->fresh()->image_path);
        $this->assertSame([], Storage::disk('public')->allFiles("channel-images/{$channel->id}"));
    }

    public function test_audit_payload_contains_no_sensitive_material(): void
    {
        $channel = $this->channel(ChannelType::Custom);
        $admin = $this->user('Super Admin');

        $this->actingAs($admin)->post($this->uploadUrl($channel), ['image' => $this->png()])->assertRedirect();

        $log = $this->coverAudits()->firstOrFail();
        $encoded = json_encode([$log->before, $log->after]);

        foreach (['password', '_token', 'csrf', 'XSRF', 'cookie', 'session', 'remember_token', 'image/png'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $encoded);
        }
    }
}