<?php

namespace Tests\Feature;

use App\Enums\AcademicYearStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Channel;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $year ??= AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);

        return SchoolClass::factory()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => GradeLevel::factory(),
            'status' => SchoolClassStatus::Active,
        ]);
    }

    private function addCover(SchoolClass $schoolClass): string
    {
        $path = "class-covers/{$schoolClass->id}/cover-".uniqid().'.jpg';
        Storage::disk('public')->put($path, 'binary');
        $schoolClass->update(['cover_image_path' => $path]);

        return $path;
    }

    public function test_classes_index_exposes_custom_cover_url(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');
        $path = $this->addCover($class);

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
        $path = $this->addCover($class);

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
        $path = $this->addCover($class);

        $classesResponse = $this->actingAs($admin)->get(route('classes.index'))->assertOk();
        $collaborationResponse = $this->actingAs($admin)->get('/collaboration')->assertOk();

        $classesResponse->assertInertia(fn (AssertableInertia $page) => $page
            ->where('classes', fn ($classes) => collect($classes)->firstWhere('id', $class->id)['coverImageUrl'] === '/storage/'.$path)
        );
        $collaborationResponse->assertInertia(fn (AssertableInertia $page) => $page
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

    public function test_uploaded_cover_is_exposed_on_collaboration(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');

        $this->actingAs($admin)->post("/classes/{$class->id}/cover", [
            'cover' => UploadedFile::fake()->image('cover.jpg', 40, 40),
        ])->assertRedirect();

        $path = $class->fresh()->cover_image_path;
        $this->assertNotNull($path);

        $this->actingAs($admin)->get('/collaboration')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('classes', fn ($classes) => collect($classes)->firstWhere('id', $class->id)['coverImageUrl'] === '/storage/'.$path)
            );
    }

    public function test_removed_cover_returns_to_null_fallback_on_both_pages(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');
        $path = $this->addCover($class);

        $this->actingAs($admin)->delete("/classes/{$class->id}/cover")->assertRedirect();

        $this->assertNull($class->fresh()->cover_image_path);
        Storage::disk('public')->assertMissing($path);

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

    public function test_cover_image_path_is_not_leaked_to_collaboration_payload(): void
    {
        $class = $this->activeClass();
        $admin = $this->user('Super Admin');
        $path = $this->addCover($class);

        $this->actingAs($admin)->get('/collaboration')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('classes', fn ($classes) => ! array_key_exists('cover_image_path', collect($classes)->firstWhere('id', $class->id)))
                ->where('classes', fn ($classes) => collect($classes)->firstWhere('id', $class->id)['coverImageUrl'] === '/storage/'.$path)
            );
    }

    public function test_collaboration_order_relations_and_channel_counts_are_preserved(): void
    {
        $year = AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);
        $zulu = $this->activeClass($year);
        $zulu->update(['name' => 'Zulu']);
        $alpha = $this->activeClass($year);
        $alpha->update(['name' => 'Alpha']);
        Channel::factory()->count(2)->create(['school_class_id' => $alpha->id]);
        $admin = $this->user('Super Admin');

        $this->actingAs($admin)->get('/collaboration')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('classes', 2)
                ->where('classes.0.id', $alpha->id)
                ->where('classes.0.name', 'Alpha')
                ->where('classes.0.channels_count', 2)
                ->where('classes.0.academic_year.id', $year->id)
                ->where('classes.0.grade_level.id', $alpha->grade_level_id)
                ->where('classes.1.id', $zulu->id)
                ->where('classes.1.name', 'Zulu')
                ->where('classes.1.channels_count', 0)
            );
    }
}
