<?php

namespace Tests\Feature;

use App\Enums\AcademicYearStatus;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Models\AcademicYear;
use App\Models\Channel;
use App\Models\SchoolClass;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Channel images are a Custom-channel-only feature.
 *
 * Gate::before grants a Super Admin a blanket allow for any ability whose first
 * argument is not in its curated deny-list, and Channel is absent from that list.
 * That bypass means ChannelPolicy::update is never evaluated for a Super Admin,
 * so without an explicit domain guard a Super Admin could attach an image to a
 * General, Announcement or Subject channel. Such an image can never render
 * (Channel::imageUrl() requires Custom), leaving invisible dead rows and
 * orphaned files under channel-images/{id}/.
 *
 * These tests pin the invariant at the HTTP boundary for both upload and remove.
 */
class ChannelImageDomainGuardTest extends TestCase
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

    private function channel(ChannelType $type, ?SchoolClass $class = null): Channel
    {
        return Channel::factory()->create([
            'school_class_id' => ($class ?? SchoolClass::factory()->create())->id,
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
        return route('collaboration.channels.image.update', [$channel->schoolClass, $channel]);
    }

    private function destroyUrl(Channel $channel): string
    {
        return route('collaboration.channels.image.destroy', [$channel->schoolClass, $channel]);
    }

    // ---------------------------------------------------------------- custom

    public function test_super_admin_can_upload_a_custom_channel_image(): void
    {
        $channel = $this->channel(ChannelType::Custom);

        $this->actingAs($this->user('Super Admin'))
            ->post($this->uploadUrl($channel), ['image' => $this->png()])
            ->assertRedirect();

        $channel->refresh();
        $this->assertNotNull($channel->image_path, 'A Super Admin must still be able to image a Custom channel.');
        $this->assertStringStartsWith("channel-images/{$channel->id}/", $channel->image_path);
        Storage::disk('public')->assertExists($channel->image_path);
        $this->assertNotNull($channel->imageUrl());
    }

    public function test_super_admin_can_remove_a_custom_channel_image(): void
    {
        $channel = $this->channel(ChannelType::Custom);
        $path = "channel-images/{$channel->id}/seeded.png";
        Storage::disk('public')->put($path, 'x');
        $channel->update(['image_path' => $path]);

        $this->actingAs($this->user('Super Admin'))
            ->delete($this->destroyUrl($channel))
            ->assertRedirect();

        $channel->refresh();
        $this->assertNull($channel->image_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_custom_channel_image_replacement_deletes_only_its_own_previous_file(): void
    {
        $channel = $this->channel(ChannelType::Custom);
        $old = "channel-images/{$channel->id}/old.png";
        Storage::disk('public')->put($old, 'x');
        $channel->update(['image_path' => $old]);

        $this->actingAs($this->user('Super Admin'))
            ->post($this->uploadUrl($channel), ['image' => $this->png()])
            ->assertRedirect();

        $channel->refresh();
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($channel->image_path);
    }

    // --------------------------------------------------------- system denied

    /** @return array<string, array{0: ChannelType}> */
    public static function systemChannelProvider(): array
    {
        return [
            'general' => [ChannelType::General],
            'announcement' => [ChannelType::Announcement],
            'subject' => [ChannelType::Subject],
        ];
    }

    #[DataProvider('systemChannelProvider')]
    public function test_super_admin_cannot_upload_an_image_to_a_system_channel(ChannelType $type): void
    {
        $channel = $this->channel($type);

        $this->actingAs($this->user('Super Admin'))
            ->post($this->uploadUrl($channel), ['image' => $this->png()])
            ->assertForbidden();

        $channel->refresh();
        $this->assertNull($channel->image_path, "A {$type->value} channel must never gain an image_path.");
        $this->assertSame([], Storage::disk('public')->allFiles("channel-images/{$channel->id}"), 'No file may be written for a system channel.');
        $this->assertNull($channel->imageUrl());
    }

    #[DataProvider('systemChannelProvider')]
    public function test_super_admin_cannot_remove_an_image_from_a_system_channel(ChannelType $type): void
    {
        $channel = $this->channel($type);
        // A pre-existing file that a denied request must NOT delete.
        $existing = "channel-images/{$channel->id}/existing.png";
        Storage::disk('public')->put($existing, 'x');
        $channel->update(['image_path' => $existing]);

        $this->actingAs($this->user('Super Admin'))
            ->delete($this->destroyUrl($channel))
            ->assertForbidden();

        $channel->refresh();
        $this->assertSame($existing, $channel->image_path, 'A denied remove must not clear the stored path.');
        $this->assertTrue(
            Storage::disk('public')->exists($existing),
            'A denied remove must not delete the file.'
        );
    }

    // --------------------------------------------------------- student denied

    public function test_student_cannot_upload_an_image_to_a_custom_channel(): void
    {
        $channel = $this->channel(ChannelType::Custom);

        $this->actingAs($this->user('Student'))
            ->post($this->uploadUrl($channel), ['image' => $this->png()])
            ->assertForbidden();

        $channel->refresh();
        $this->assertNull($channel->image_path);
        $this->assertSame([], Storage::disk('public')->allFiles("channel-images/{$channel->id}"));
    }

    public function test_student_cannot_remove_an_image_from_a_custom_channel(): void
    {
        $channel = $this->channel(ChannelType::Custom);
        $path = "channel-images/{$channel->id}/seeded.png";
        Storage::disk('public')->put($path, 'x');
        $channel->update(['image_path' => $path]);

        $this->actingAs($this->user('Student'))
            ->delete($this->destroyUrl($channel))
            ->assertForbidden();

        $channel->refresh();
        $this->assertSame($path, $channel->image_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_student_cannot_upload_an_image_to_a_system_channel(): void
    {
        $channel = $this->channel(ChannelType::General);

        $this->actingAs($this->user('Student'))
            ->post($this->uploadUrl($channel), ['image' => $this->png()])
            ->assertForbidden();

        $channel->refresh();
        $this->assertNull($channel->image_path);
    }

    /**
     * The guard must not depend on the Gate::before Super Admin shortcut, and it
     * must not weaken the existing contract for a Custom channel.
     */
    public function test_current_class_teacher_may_manage_a_custom_channel_image(): void
    {
        $year = AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);
        $class = SchoolClass::factory()->create(['academic_year_id' => $year->id, 'status' => \App\Enums\SchoolClassStatus::Active]);
        $channel = $this->channel(ChannelType::Custom, $class);
        $teacher = $this->user('Teacher');
        \App\Models\TeacherProfile::factory()->create(['user_id' => $teacher->id]);
        $class->teacherAssignments()->create([
            'teacher_profile_id' => \App\Models\TeacherProfile::where('user_id', $teacher->id)->value('id'),
            'academic_year_id' => $class->academic_year_id,
            'current_slot' => 1,
            'starts_on' => now()->toDateString(),
        ]);

        $this->actingAs($teacher)
            ->post($this->uploadUrl($channel), ['image' => $this->png()])
            ->assertRedirect();

        $this->assertNotNull($channel->fresh()->image_path);
    }

    public function test_cross_class_channel_image_is_not_routable(): void
    {
        $channel = $this->channel(ChannelType::Custom);

        $this->actingAs($this->user('Super Admin'))
            ->post(route('collaboration.channels.image.update', [SchoolClass::factory()->create(), $channel]), ['image' => $this->png()])
            ->assertNotFound();

        $this->assertNull($channel->fresh()->image_path);
    }
}