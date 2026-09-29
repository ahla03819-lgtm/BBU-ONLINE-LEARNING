<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Locale;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserLocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_locale_defaults_to_english(): void
    {
        $user = User::factory()->create();

        $this->assertSame('en', $user->fresh()->locale);
        $this->assertSame('en', $user->preferredLocale());
    }

    public function test_the_language_preference_requires_an_authenticated_user(): void
    {
        $this->put('/my-account/locale', ['locale' => 'km'])->assertRedirect('/login');
    }

    public function test_an_authenticated_user_can_switch_to_khmer(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->put('/my-account/locale', ['locale' => 'km'])
            ->assertRedirect(route('my-account.show', ['section' => 'appearance']));

        $this->assertSame('km', $user->fresh()->locale);
    }

    public function test_an_authenticated_user_can_switch_back_to_english(): void
    {
        $user = User::factory()->create(['locale' => 'km']);

        $this->actingAs($user)->put('/my-account/locale', ['locale' => 'en'])->assertRedirect();

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_the_preference_persists_across_requests_and_navigation(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)->put('/my-account/locale', ['locale' => 'km']);

        // A later Inertia navigation shares the stored preference, so the shell
        // keeps rendering Khmer without a reload.
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'km'));

        $this->actingAs($user)
            ->get('/my-account/appearance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'km')
                ->where('account.locale', 'km'));
    }

    public function test_a_json_client_receives_the_preference_without_a_redirect(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        // The header selector persists through fetch, so a redirected PUT would
        // be replayed against the redirect target and rejected.
        $this->actingAs($user)
            ->putJson('/my-account/locale', ['locale' => 'km'])
            ->assertOk()
            ->assertExactJson(['locale' => 'km']);

        $this->assertSame('km', $user->fresh()->locale);
    }

    public function test_a_json_client_is_rejected_for_an_unsupported_locale(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->putJson('/my-account/locale', ['locale' => 'fr'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locale');

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_only_supported_locales_are_accepted(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        foreach (['fr', 'zh', 'km-KH', 'EN', '', 'english', 'k', 123, ['km']] as $unsupported) {
            $this->actingAs($user)
                ->put('/my-account/locale', ['locale' => $unsupported])
                ->assertSessionHasErrors('locale');
        }

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_surrounding_whitespace_is_normalised_before_validation(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->put('/my-account/locale', ['locale' => '  km  '])
            ->assertSessionHasNoErrors();

        $this->assertSame('km', $user->fresh()->locale);
    }

    public function test_a_missing_locale_is_rejected_without_changing_the_preference(): void
    {
        $user = User::factory()->create(['locale' => 'km']);

        $this->actingAs($user)
            ->put('/my-account/locale', [])
            ->assertSessionHasErrors('locale');

        $this->assertSame('km', $user->fresh()->locale);
    }

    public function test_one_account_cannot_change_another_accounts_preference(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        $other = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)->put('/my-account/locale', ['locale' => 'km']);

        $this->assertSame('km', $user->fresh()->locale);
        $this->assertSame('en', $other->fresh()->locale);
    }

    public function test_a_guest_receives_english(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_an_unrecognised_stored_value_is_presented_as_english(): void
    {
        $user = User::factory()->create();

        // Simulate a value written by a future or rolled-back release.
        $user->forceFill(['locale' => 'xx'])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_the_locale_support_list_is_limited_to_english_and_khmer(): void
    {
        $this->assertSame(['en', 'km'], Locale::SUPPORTED);
        $this->assertSame('en', Locale::DEFAULT);
        $this->assertSame(['en' => 'English', 'km' => 'ខ្មែរ'], Locale::options());
    }
}
