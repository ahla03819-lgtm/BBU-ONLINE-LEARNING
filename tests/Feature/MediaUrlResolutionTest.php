<?php

namespace Tests\Feature;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Media URL resolvers must degrade to null when the managed file is absent, so
 * the UI shows an initials/gradient/icon fallback instead of emitting a URL that
 * is guaranteed to fail on every page load.
 *
 * Regression coverage for the avatar "initials instead of photos" class of bug:
 * a DB row can outlive its file (worktree switch, manual cleanup), and the
 * resolver is what decides whether the browser sees a photo or initials.
 */
class MediaUrlResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_avatar_url_returns_public_url_when_managed_file_exists(): void
    {
        $user = User::factory()->create();
        $path = "user-avatars/{$user->id}/".'a1b2c3d4-1111-2222-3333-444455556666.jpg';
        Storage::disk('public')->put($path, 'binary');
        $user->update(['avatar_path' => $path]);

        $url = $user->fresh()->avatarUrl();

        $this->assertNotNull($url, 'A present managed avatar must resolve to a public URL.');
        $this->assertStringContainsString('/storage/'.$path, $url);
        $this->assertStringNotContainsString('..', $url);
    }

    public function test_avatar_url_is_null_when_managed_file_is_missing(): void
    {
        $user = User::factory()->create();
        // Managed, correctly scoped path — but no file was ever written.
        $user->update(['avatar_path' => "user-avatars/{$user->id}/missing.jpg"]);

        $this->assertNull(
            $user->fresh()->avatarUrl(),
            'A managed avatar path with no file on disk must degrade to null so the client renders initials.'
        );
    }

    public function test_avatar_url_is_null_without_a_path(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->assertNull($user->fresh()->avatarUrl());
    }

    public function test_avatar_url_rejects_paths_outside_the_users_own_managed_directory(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Another user's managed directory must never be served through this user.
        $user->update(['avatar_path' => "user-avatars/{$other->id}/steal.jpg"]);
        $this->assertNull($user->fresh()->avatarUrl());

        // Traversal attempts must never produce a URL.
        $user->update(['avatar_path' => 'user-avatars/'.$user->id.'/../../../.env']);
        $this->assertNull($user->fresh()->avatarUrl());
    }

    public function test_avatar_url_never_exposes_a_raw_storage_path(): void
    {
        $user = User::factory()->create();
        $path = "user-avatars/{$user->id}/".'ffffffff-9999-8888-7777-666655554444.png';
        Storage::disk('public')->put($path, 'binary');
        $user->update(['avatar_path' => $path]);

        $url = $user->fresh()->avatarUrl();

        // Storage::fake() serves relative URLs (the real disk prefixes APP_URL);
        // what matters is that the resolver returns the served /storage/ URL
        // rather than the raw storage path held in the database.
        $this->assertNotNull($url);
        $this->assertNotSame($path, $url, 'avatarUrl() must not return the bare storage path.');
        $this->assertStringContainsString('/storage/', $url);
        $this->assertStringStartsNotWith('user-avatars/', $url);
    }

    public function test_class_cover_url_is_null_when_managed_file_is_missing(): void
    {
        $class = SchoolClass::factory()->create();
        $class->update(['cover_image_path' => "class-covers/{$class->id}/lost.jpg"]);

        $this->assertNull($class->fresh()->coverImageUrl());
    }

    public function test_class_cover_url_returns_public_url_when_managed_file_exists(): void
    {
        $class = SchoolClass::factory()->create();
        $path = "class-covers/{$class->id}/".'abcdabcd-1111-2222-3333-444455556666.jpg';
        Storage::disk('public')->put($path, 'binary');
        $class->update(['cover_image_path' => $path]);

        $this->assertStringContainsString('/storage/'.$path, $class->fresh()->coverImageUrl());
    }

    public function test_class_cover_url_rejects_paths_outside_the_class_managed_directory(): void
    {
        $class = SchoolClass::factory()->create();
        $other = SchoolClass::factory()->create();

        $class->update(['cover_image_path' => "class-covers/{$other->id}/theirs.jpg"]);

        $this->assertNull($class->fresh()->coverImageUrl());
    }

    public function test_channel_image_url_is_null_when_managed_file_is_missing(): void
    {
        $channel = Channel::factory()->create(['type' => ChannelType::Custom, 'status' => ChannelStatus::Active]);
        $channel->update(['image_path' => "channel-images/{$channel->id}/gone.jpg"]);

        $this->assertNull(
            $channel->fresh()->imageUrl(),
            'A managed channel image with no file on disk must degrade to null so the fallback icon returns.'
        );
    }

    public function test_channel_image_url_returns_public_url_when_managed_file_exists(): void
    {
        $channel = Channel::factory()->create(['type' => ChannelType::Custom, 'status' => ChannelStatus::Active]);
        $path = "channel-images/{$channel->id}/".'12341234-1111-2222-3333-444455556666.webp';
        Storage::disk('public')->put($path, 'binary');
        $channel->update(['image_path' => $path]);

        $this->assertStringContainsString('/storage/'.$path, $channel->fresh()->imageUrl());
    }

    /**
     * System channels must never gain a custom image, even if a path is somehow
     * present in the row.
     */
    public function test_channel_image_url_is_null_for_system_channel_types(): void
    {
        foreach ([ChannelType::General, ChannelType::Announcement, ChannelType::Subject] as $type) {
            $channel = Channel::factory()->create(['type' => $type, 'status' => ChannelStatus::Active]);
            $path = "channel-images/{$channel->id}/system.jpg";
            Storage::disk('public')->put($path, 'binary');
            $channel->update(['image_path' => $path]);

            $this->assertNull(
                $channel->fresh()->imageUrl(),
                "A {$type->value} channel must never expose a custom image URL."
            );
        }
    }

    public function test_channel_image_url_rejects_paths_outside_the_channel_managed_directory(): void
    {
        $channel = Channel::factory()->create(['type' => ChannelType::Custom, 'status' => ChannelStatus::Active]);
        $other = Channel::factory()->create(['type' => ChannelType::Custom, 'status' => ChannelStatus::Active]);

        $channel->update(['image_path' => "channel-images/{$other->id}/theirs.jpg"]);

        $this->assertNull($channel->fresh()->imageUrl());
    }

    public function test_channel_raw_image_path_is_never_serialised_to_the_client(): void
    {
        $channel = Channel::factory()->create(['type' => ChannelType::Custom, 'status' => ChannelStatus::Active]);
        $channel->update(['image_path' => "channel-images/{$channel->id}/secret.jpg"]);

        $this->assertArrayNotHasKey(
            'image_path',
            $channel->fresh()->toArray(),
            'The raw storage path must stay hidden; only imageUrl() may reach the client.'
        );
    }
}