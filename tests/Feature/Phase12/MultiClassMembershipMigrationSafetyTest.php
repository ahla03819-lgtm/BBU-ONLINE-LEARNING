<?php

namespace Tests\Feature\Phase12;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MultiClassMembershipMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_compatible_rollback_restores_the_legacy_index_and_preserves_rows(): void
    {
        $year = AcademicYear::factory()->create();
        $class = SchoolClass::factory()->create(['academic_year_id' => $year->id]);
        Enrollment::factory()->create(['student_profile_id' => StudentProfile::factory(), 'academic_year_id' => $year->id, 'school_class_id' => $class->id, 'current_slot' => 1]);
        $migration = $this->migration();

        $migration->down();

        $this->assertSame(1, Enrollment::count());
        $this->assertTrue($this->hasUniqueIndex('enrollments_one_current_per_year', ['student_profile_id', 'academic_year_id', 'current_slot']));
        $this->assertFalse($this->hasUniqueIndex('enrollments_one_current_per_class', ['student_profile_id', 'school_class_id', 'current_slot']));

        $migration->up();

        $this->assertSame(1, Enrollment::count());
        $this->assertTrue($this->hasUniqueIndex('enrollments_one_current_per_class', ['student_profile_id', 'school_class_id', 'current_slot']));
    }

    public function test_incompatible_rollback_aborts_before_altering_the_per_class_index(): void
    {
        $year = AcademicYear::factory()->create();
        $first = SchoolClass::factory()->create(['academic_year_id' => $year->id]);
        $second = SchoolClass::factory()->create(['academic_year_id' => $year->id]);
        $student = StudentProfile::factory()->create();
        Enrollment::factory()->create(['student_profile_id' => $student->id, 'academic_year_id' => $year->id, 'school_class_id' => $first->id, 'current_slot' => 1]);
        Enrollment::factory()->create(['student_profile_id' => $student->id, 'academic_year_id' => $year->id, 'school_class_id' => $second->id, 'current_slot' => 1]);

        try {
            $this->migration()->down();
            $this->fail('The rollback should have rejected incompatible multi-class memberships.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('multiple current class memberships', $exception->getMessage());
        }

        $this->assertSame(2, Enrollment::count());
        $this->assertTrue($this->hasUniqueIndex('enrollments_one_current_per_class', ['student_profile_id', 'school_class_id', 'current_slot']));
        $this->assertFalse($this->hasUniqueIndex('enrollments_one_current_per_year', ['student_profile_id', 'academic_year_id', 'current_slot']));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_31_130000_allow_multiple_current_class_memberships.php');
    }

    /** @param array<int, string> $columns */
    private function hasUniqueIndex(string $name, array $columns): bool
    {
        return collect(Schema::getIndexes('enrollments'))->contains(
            fn (array $index) => $index['name'] === $name && $index['unique'] && $index['columns'] === $columns,
        );
    }
}
