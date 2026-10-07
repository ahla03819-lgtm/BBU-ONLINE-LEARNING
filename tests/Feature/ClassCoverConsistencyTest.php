<?php

namespace Tests\Feature;

use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Class covers must be exposed consistently to both Classes and Collaboration
 * pages. The Collaboration index previously passed raw Eloquent models without
 * the canonical coverImageUrl, so a custom cover uploaded from Classes would
 * not appear on Collaboration.
 */
class ClassCoverConsistencyTest extends TestCase
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

    private function activeClass(?AcademicYear $year = null): SchoolClass
    {
        $year ??= AcademicYear::factory()->create(['status' => \App\Enums\AcademicYearStatus::Active, 'active_slot' => 1]);

        return SchoolClass::factory()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => GradeLevel::factory(),
            'status' => SchoolClassStatus::Active,
        ]);
    }

    public function test_classes_index_exposes_custom_cover_url(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');
        $path = "class-covers/{$class->id}/".'cover-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, 'binary');
        $class->update(['cover_image_path' => $path]);

        $this->actingAs($admin)->get(route('classes.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Classes/Index')
                ->where('classes.0.id', $class->id)
                ->where('classes.0.coverImageUrl', '/storage/'.$path)
            );
    }

    public function test_collaboration_index_exposes_the_same_custom_cover_url(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');
        $path = "class-covers/{$class->id}/".'cover-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, 'binary');
        $class->update(['cover_image_path' => $path]);

        $this->actingAs($admin)->get('/collaboration')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Collaboration/Index')
                ->where('classes.0.id', $class->id)
                ->where('classes.0.coverImageUrl', '/storage/'.$path)
            );
    }

    public function test_both_pages_expose_the_same_cover_url_for_the_same_class(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');
        $path = "class-covers/{$class->id}/".'cover-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, 'binary');
        $class->update(['cover_image_path' => $path]);

        $classesResponse = $this->actingAs($admin)->get(route('classes.index'))->assertOk();
        $collabResponse = $this->actingAs($admin)->get('/collaboration')->assertOk();

        $classesResponse->assertInertia(fn (AssertableInertia $page) => $page
            ->where('classes', fn ($classes) => collect($classes)->firstWhere('id', $class->id)['coverImageUrl'] === '/storage/'.$path)
        );
        $collabResponse->assertInertia(fn (AssertableInertia $page) => $page
            ->where('classes', fn ($classes) => collect($classes)->firstWhere('id', $class->id)['coverImageUrl'] === '/storage/'.$path)
        );
    }

    public function test_class_without_cover_has_null_cover_image_url_on_both_pages(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');

        $this->actingAs($admin)->get(route('classes.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('classes', fn ($classes) => collect($classes)->firstWhere('id', $class->id)['coverImageUrl'] === null)
            );

        $this->actingAs($admin)->get('/collaboration')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('classes', fn ($classes) => collect($classes)->firstWhere('id', $class->id)['coverImageUrl'] === null)
            );
    }

    public function test_cover_update_persists_and_is_exposed_on_collaboration(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');

        $this->actingAs($admin)->post("/classes/{$class->id}/cover", [
            'cover' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg', 40, 40),
        ])->assertRedirect();

        $path = $class->fresh()->cover_image_path;
        $this->assertNotNull($path);

        $this->actingAs($admin)->get('/collaboration')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('classes', fn ($classes) => collect($classes)->firstWhere('id', $class->id)['coverImageUrl'] === '/storage/'.$path)
            );
    }

    public function test_cover_image_path_is_not_leaked_to_collaboration_payload(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');
        $path = "class-covers/{$class->id}/".'cover-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, 'binary');
        $class->update(['cover_image_path' => $path]);

        $this->actingAs($admin)->get('/collaboration')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('classes', fn ($classes) => array_key_exists('cover_image_path', collect($classes)->firstWhere('id', $class->id)) === false)
                ->where('classes', fn ($classes) => collect($classes)->firstWhere('id', $class->id)['coverImageUrl'] === '/storage/'.$path)
            );
    }
}
