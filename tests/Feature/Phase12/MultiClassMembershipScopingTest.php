<?php

namespace Tests\Feature\Phase12;

use App\Actions\People\EnrollStudent;
use App\Enums\AcademicYearStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\CollaborationAccess;
use App\Services\CourseworkAccess;
use App\Services\MeetingAccess;
use App\Services\ResultsAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiClassMembershipScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_student_memberships_are_class_scoped_across_authorized_modules(): void
    {
        $year = AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);
        $first = SchoolClass::factory()->create(['academic_year_id' => $year->id, 'status' => SchoolClassStatus::Active]);
        $second = SchoolClass::factory()->create(['academic_year_id' => $year->id, 'status' => SchoolClassStatus::Active]);
        $unrelated = SchoolClass::factory()->create(['academic_year_id' => $year->id, 'status' => SchoolClassStatus::Active]);
        $user = tap(User::factory()->create(), fn (User $user) => $user->assignRole('Student'));
        $student = StudentProfile::factory()->create(['user_id' => $user->id]);

        app(EnrollStudent::class)->handle($student, $first, '2026-09-01');
        app(EnrollStudent::class)->handle($student, $second, '2026-09-02');

        $this->assertEqualsCanonicalizing([$first->id, $second->id], app(CollaborationAccess::class)->classesFor($user)->pluck('id')->all());
        $this->assertTrue(app(CourseworkAccess::class)->canAccessClass($user, $first));
        $this->assertTrue(app(CourseworkAccess::class)->canAccessClass($user, $second));
        $this->assertFalse(app(CourseworkAccess::class)->canAccessClass($user, $unrelated));
        $this->assertTrue(app(MeetingAccess::class)->canAccessClass($user, $first));
        $this->assertTrue(app(MeetingAccess::class)->canAccessClass($user, $second));
        $this->assertFalse(app(MeetingAccess::class)->canAccessClass($user, $unrelated));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], app(ResultsAccess::class)->currentStudentClasses($user)->pluck('id')->all());
        $this->assertTrue($user->can('view', $first));
        $this->assertFalse($user->can('view', $unrelated));
    }
}
