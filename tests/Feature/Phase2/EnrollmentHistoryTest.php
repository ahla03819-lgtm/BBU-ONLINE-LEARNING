<?php

namespace Tests\Feature\Phase2;

use App\Actions\People\EndEnrollment;
use App\Actions\People\EnrollStudent;
use App\Actions\People\TransferStudent;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_year_is_derived_from_the_selected_class(): void
    {
        $class = SchoolClass::factory()->create();
        $student = StudentProfile::factory()->create();
        $enrollment = app(EnrollStudent::class)->handle($student, $class, '2026-09-01');

        $this->assertSame($class->academic_year_id, $enrollment->academic_year_id);
    }

    public function test_only_one_current_enrollment_per_student_and_year_is_allowed(): void
    {
        $year = AcademicYear::factory()->create();
        $first = SchoolClass::factory()->create(['academic_year_id' => $year]);
        $second = SchoolClass::factory()->create(['academic_year_id' => $year]);
        $student = StudentProfile::factory()->create();
        app(EnrollStudent::class)->handle($student, $first, '2026-09-01');

        $this->expectException(\DomainException::class);
        app(EnrollStudent::class)->handle($student, $second, '2026-09-02');
    }

    public function test_transfer_preserves_history_and_creates_one_new_current_enrollment(): void
    {
        $year = AcademicYear::factory()->create();
        $first = SchoolClass::factory()->create(['academic_year_id' => $year]);
        $second = SchoolClass::factory()->create(['academic_year_id' => $year]);
        $student = StudentProfile::factory()->create();
        $old = app(EnrollStudent::class)->handle($student, $first, '2026-09-01');

        $new = app(TransferStudent::class)->handle($old, $second, '2026-10-01', 'Class change');

        $this->assertNull($old->fresh()->current_slot);
        $this->assertSame('Class change', $old->fresh()->end_reason);
        $this->assertSame(1, $new->current_slot);
        $this->assertSame($second->id, $new->school_class_id);
        $this->assertCount(2, $student->enrollments()->get());
    }

    public function test_ended_student_can_be_reenrolled_in_the_same_year(): void
    {
        $class = SchoolClass::factory()->create();
        $student = StudentProfile::factory()->create();
        $old = app(EnrollStudent::class)->handle($student, $class, '2026-09-01');
        app(EndEnrollment::class)->handle($old, '2026-09-30', 'Withdrawn');

        $new = app(EnrollStudent::class)->handle($student, $class, '2026-10-15');

        $this->assertSame(1, $new->current_slot);
        $this->assertSame(2, Enrollment::count());
    }
}
