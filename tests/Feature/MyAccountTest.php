<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MyAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_my_account_routes_require_an_authenticated_verified_user(): void
    {
        $this->get('/my-account')->assertRedirect('/login');
        $this->get('/my-account/security')->assertRedirect('/login');
    }

    public function test_authenticated_user_only_receives_their_own_account_data(): void
    {
        $user = User::factory()->create(['name' => 'Current User']);
        StudentProfile::factory()->create(['user_id' => $user, 'student_number' => 'BBU-100']);
        $other = User::factory()->create(['name' => 'Other User', 'email' => 'other@example.test']);

        $this->actingAs($user)->get('/my-account')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('MyAccount/Show')
            ->where('section', 'profile')
            ->where('account.name', 'Current User')
            ->where('account.email', $user->email)
            ->where('account.context.identifier', 'BBU-100')
            ->where('account.avatar_url', null)
            ->missing('account.id')
            ->missing('account.avatar_path')
            ->missing('account.password')
            ->missing('account.'.$other->id));
        $this->actingAs($user)->get('/my-account/'.$other->id)->assertNotFound();
    }

    public function test_user_can_update_only_their_own_name_and_not_their_email_or_role(): void
    {
        $user = User::factory()->create(['name' => 'Before', 'email' => 'before@example.test']);
        $user->assignRole('Student');

        $this->actingAs($user)->patch('/my-account/profile', [
            'name' => 'After',
            'email' => 'attempt@example.test',
            'role' => 'Super Admin',
        ])->assertRedirect('/my-account');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'After', 'email' => 'before@example.test']);
        $this->assertTrue($user->fresh()->hasRole('Student'));
        $this->assertFalse($user->fresh()->hasRole('Super Admin'));
    }

    public function test_password_change_requires_the_current_password_and_uses_secure_validation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/my-account/security', [
            'current_password' => 'incorrect-password',
            'password' => 'Correct-Horse-99',
            'password_confirmation' => 'Correct-Horse-99',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->actingAs($user)->put('/my-account/security', [
            'current_password' => 'password',
            'password' => 'Correct-Horse-99',
            'password_confirmation' => 'different-password',
        ])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->actingAs($user)->put('/my-account/security', [
            'current_password' => 'password',
            'password' => 'Correct-Horse-99',
            'password_confirmation' => 'Correct-Horse-99',
        ])->assertRedirect('/my-account/security');
        $this->assertTrue(Hash::check('Correct-Horse-99', $user->fresh()->password));
    }

    public function test_authenticated_user_can_upload_a_valid_avatar_to_their_own_managed_path(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/my-account/avatar', ['avatar' => UploadedFile::fake()->image('portrait.png', 300, 300)])
            ->assertRedirect('/my-account');

        $path = $user->fresh()->avatar_path;
        $this->assertMatchesRegularExpression('#^user-avatars/'.$user->id.'/[a-f0-9-]+\\.png$#', (string) $path);
        Storage::disk('public')->assertExists($path);
        $this->actingAs($user)->get('/my-account')->assertInertia(fn (Assert $page) => $page->where('account.avatar_url', Storage::disk('public')->url($path)));
    }

    public function test_guests_cannot_upload_an_avatar(): void
    {
        $this->post('/my-account/avatar', ['avatar' => UploadedFile::fake()->image('portrait.png')])->assertRedirect('/login');
    }

    public function test_unsupported_or_oversized_avatar_files_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/my-account/avatar', ['avatar' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('avatar');
        $this->actingAs($user)->post('/my-account/avatar', ['avatar' => UploadedFile::fake()->image('large.png')->size(2049)])
            ->assertSessionHasErrors('avatar');
        $this->actingAs($user)->post('/my-account/avatar', ['avatar' => UploadedFile::fake()->image('wide.png', 4097, 1)])
            ->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_replacing_and_removing_an_avatar_only_affects_the_authenticated_account(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $ownerPath = "user-avatars/{$owner->id}/original.png";
        $owner->update(['avatar_path' => $ownerPath]);
        Storage::disk('public')->put($ownerPath, 'original');
        $other = User::factory()->create();
        $otherPath = "user-avatars/{$other->id}/other.png";
        $other->update(['avatar_path' => $otherPath]);
        Storage::disk('public')->put($otherPath, 'other');

        $this->actingAs($owner)->post('/my-account/avatar?user_id='.$other->id, ['avatar' => UploadedFile::fake()->image('replacement.webp', 200, 200)])
            ->assertRedirect('/my-account');

        $replacementPath = $owner->fresh()->avatar_path;
        Storage::disk('public')->assertExists($replacementPath);
        Storage::disk('public')->assertMissing($ownerPath);
        $this->assertSame($otherPath, $other->fresh()->avatar_path);
        Storage::disk('public')->assertExists($otherPath);

        $this->actingAs($owner)->delete('/my-account/avatar?user_id='.$other->id)->assertRedirect('/my-account');

        $this->assertNull($owner->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($replacementPath);
        $this->assertSame($otherPath, $other->fresh()->avatar_path);
        Storage::disk('public')->assertExists($otherPath);
    }

    public function test_avatar_write_failure_preserves_the_existing_path_without_storing_an_invalid_value(): void
    {
        $user = User::factory()->create();
        $previousPath = $user->managedAvatarDirectory().'/original.png';
        $user->update(['avatar_path' => $previousPath]);
        $disk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('putFileAs')->once()->andReturnFalse();
        $factory = \Mockery::mock(\Illuminate\Contracts\Filesystem\Factory::class);
        $factory->shouldReceive('disk')->with('public')->once()->andReturn($disk);
        $this->app->instance(\Illuminate\Contracts\Filesystem\Factory::class, $factory);

        $this->actingAs($user)->post('/my-account/avatar', ['avatar' => UploadedFile::fake()->image('portrait.png')])
            ->assertSessionHasErrors('avatar');

        $this->assertSame($previousPath, $user->fresh()->avatar_path);
    }

    public function test_avatar_database_failure_cleans_the_new_file_and_keeps_the_existing_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $previousPath = $user->managedAvatarDirectory().'/original.png';
        $user->update(['avatar_path' => $previousPath]);
        Storage::disk('public')->put($previousPath, 'original');
        User::updating(fn () => throw new \RuntimeException('Simulated database failure'));

        $this->actingAs($user)->post('/my-account/avatar', ['avatar' => UploadedFile::fake()->image('portrait.png')])
            ->assertSessionHasErrors('avatar');

        $this->assertSame($previousPath, $user->fresh()->avatar_path);
        Storage::disk('public')->assertExists($previousPath);
        $this->assertSame([$previousPath], Storage::disk('public')->allFiles($user->managedAvatarDirectory()));
    }

    public function test_avatar_delete_failure_restores_the_existing_path(): void
    {
        $user = User::factory()->create();
        $path = $user->managedAvatarDirectory().'/original.png';
        $user->update(['avatar_path' => $path]);
        $disk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        $disk->shouldReceive('url')->zeroOrMoreTimes()->with($path)->andReturn('/storage/'.$path);
        Storage::shouldReceive('disk')->with('public')->zeroOrMoreTimes()->andReturn($disk);

        $this->actingAs($user)->delete('/my-account/avatar')->assertSessionHasErrors('avatar');

        $this->assertSame($path, $user->fresh()->avatar_path);
    }

    public function test_managed_avatar_path_validation_rejects_traversal_ambiguous_and_other_user_paths(): void
    {
        $user = User::factory()->create();
        $validPath = $user->managedAvatarDirectory().'/4e4f7339-a9e6-4592-bcc1-3767b3da8ec8.png';
        $otherPath = 'user-avatars/'.($user->id + 1).'/avatar.png';

        $this->assertTrue($user->ownsManagedAvatarPath($validPath));

        foreach ([
            $user->managedAvatarDirectory().'/../avatar.png',
            $user->managedAvatarDirectory().'\\avatar.png',
            $user->managedAvatarDirectory().'//avatar.png',
            $user->managedAvatarDirectory().'/',
            $user->managedAvatarDirectory().'/avatar/extra.png',
            $otherPath,
        ] as $invalidPath) {
            $this->assertFalse($user->ownsManagedAvatarPath($invalidPath));
        }
    }

    public function test_traversal_like_avatar_paths_are_not_deleted_during_removal(): void
    {
        $user = User::factory()->create();
        $user->update(['avatar_path' => $user->managedAvatarDirectory().'/../avatar.png']);
        Storage::shouldReceive('disk')->never();

        $this->actingAs($user)->delete('/my-account/avatar')->assertRedirect('/my-account');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_unmanaged_avatar_paths_are_not_deleted_during_removal(): void
    {
        $user = User::factory()->create(['avatar_path' => 'other-user/avatar.png']);
        Storage::shouldReceive('disk')->never();

        $this->actingAs($user)->delete('/my-account/avatar')->assertRedirect('/my-account');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_account_ui_exposes_real_sections_and_moves_sound_setting_out_of_the_topbar(): void
    {
        $page = file_get_contents(resource_path('js/Pages/MyAccount/Show.jsx'));
        $topbar = file_get_contents(resource_path('js/Components/UI/AppTopbar.jsx'));
        $avatar = file_get_contents(resource_path('js/Components/UI/UserAvatar.jsx'));

        foreach (['Profile', 'Security', 'Notifications & Sounds', 'Appearance', 'Application sounds', 'role="switch"', 'This preference is stored only in this browser.', 'Profile photo', 'Change photo', 'Save photo', 'Remove photo', 'image/jpeg,image/png,image/webp'] as $contract) {
            $this->assertStringContainsString($contract, $page);
        }
        $this->assertStringContainsString('object-cover', $avatar);
        foreach (['lg:grid-cols-[minmax(17.5rem,20rem)_minmax(0,1fr)]', 'sm:grid-cols-2', 'min-w-0', 'leading-4'] as $contract) {
            $this->assertStringContainsString($contract, $page);
        }
        foreach (['Enter your current password', 'Enter a new password', 'Confirm your new password', "'Show password'", "'Hide password'", 'aria-invalid', 'aria-describedby', 'current-password', 'new-password'] as $contract) {
            $this->assertStringContainsString($contract, $page);
        }
        $this->assertStringNotContainsString('overflow-x-auto', $page);
        foreach (['My Account', 'Settings', 'Sign out', '/my-account'] as $contract) {
            $this->assertStringContainsString($contract, $topbar);
        }
        $this->assertStringNotContainsString('soundsEnabled', $topbar);
        $this->assertStringNotContainsString('onToggleSounds', $topbar);
    }
}
