<?php

namespace Tests\Feature\Phase3;

use App\Actions\Collaboration\ProvisionDefaultChannels;
use App\Actions\People\EnrollStudent;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CollaborationScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_student_index_contains_only_current_class_metadata(): void
    {
        $year = AcademicYear::factory()->active()->create();
        $own = SchoolClass::factory()->create(['academic_year_id' => $year, 'grade_level_id' => GradeLevel::factory(), 'status' => SchoolClassStatus::Active]);
        $other = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);
        app(ProvisionDefaultChannels::class)->handle($own);
        app(ProvisionDefaultChannels::class)->handle($other);
        $user = User::factory()->create();
        $user->assignRole('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user]);
        app(EnrollStudent::class)->handle($profile, $own, '2026-09-01');
        $this->actingAs($user)->get('/collaboration')->assertInertia(fn (AssertableInertia $page) => $page->component('Collaboration/Index')->has('classes', 1)->where('classes.0.id', $own->id));
    }
}
