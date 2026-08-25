<?php

namespace Tests\Feature\Phase2;

use App\Actions\Academics\SaveAcademicYear;
use App\Enums\AcademicYearStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_only_one_academic_year_can_be_active(): void
    {
        $first = AcademicYear::factory()->active()->create();
        $second = AcademicYear::factory()->create();

        app(SaveAcademicYear::class)->handle($second, [
            'name' => $second->name,
            'starts_on' => $second->starts_on,
            'ends_on' => $second->ends_on,
            'status' => AcademicYearStatus::Active->value,
        ]);

        $this->assertSame(AcademicYearStatus::Closed, $first->fresh()->status);
        $this->assertNull($first->fresh()->active_slot);
        $this->assertSame(1, $second->fresh()->active_slot);
        $this->assertDatabaseCount('academic_years', 2);
    }

    public function test_database_rejects_two_active_slots(): void
    {
        AcademicYear::factory()->active()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        AcademicYear::factory()->active()->create();
    }

    public function test_school_class_uses_the_required_status_enum(): void
    {
        $class = SchoolClass::factory()->create(['status' => SchoolClassStatus::Closed]);
        $this->assertSame(SchoolClassStatus::Closed, $class->status);
    }

    public function test_admin_can_create_each_academic_catalog_resource(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $year = AcademicYear::factory()->make();

        $this->actingAs($admin)->post('/academic-years', [
            'name' => $year->name, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'status' => 'planned',
        ])->assertRedirect();
        $this->actingAs($admin)->post('/grade-levels', ['name' => 'Grade 7', 'sequence' => 7, 'is_active' => true])->assertRedirect();
        $this->actingAs($admin)->post('/subjects', ['code' => 'math-7', 'name' => 'Mathematics 7', 'is_active' => true])->assertRedirect();
        $this->actingAs($admin)->post('/school-classes', [
            'academic_year_id' => AcademicYear::first()->id, 'grade_level_id' => GradeLevel::first()->id,
            'name' => 'Grade 7', 'section' => 'A', 'status' => 'active', 'capacity' => 30,
        ])->assertRedirect();

        $this->assertDatabaseHas('subjects', ['code' => 'MATH-7']);
        $this->assertDatabaseHas('school_classes', ['status' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'school-class.created', 'actor_id' => $admin->id]);
    }
}
