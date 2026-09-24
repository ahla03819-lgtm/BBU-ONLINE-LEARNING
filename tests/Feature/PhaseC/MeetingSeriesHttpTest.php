<?php

namespace Tests\Feature\PhaseC;

use App\Enums\AcademicYearStatus;
use App\Enums\MeetingRecurrenceType;
use App\Enums\MeetingSeriesStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\MeetingSeries;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingSeriesHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_create_authorization_follows_existing_role_and_class_relationships(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);
        $unrelatedTeacher = $this->roleUser('Teacher');
        $student = $this->student($class);
        $admin = $this->roleUser('Admin');
        $superAdmin = $this->roleUser('Super Admin');

        $this->actingAs($teacher)->postJson(route('meeting-series.store', $class), $this->payload())
            ->assertCreated();
        $this->actingAs($unrelatedTeacher)->postJson(route('meeting-series.store', $class), $this->payload())
            ->assertForbidden();
        $this->actingAs($student)->postJson(route('meeting-series.store', $class), $this->payload())
            ->assertForbidden();
        $this->actingAs($admin)->postJson(route('meeting-series.store', $class), $this->payload([
            'title' => 'Admin-created series',
            'host_user_id' => $teacher->id,
        ]))->assertCreated();
        $this->actingAs($superAdmin)->postJson(route('meeting-series.store', $class), $this->payload([
            'title' => 'Super-admin-created series',
            'host_user_id' => $superAdmin->id,
        ]))->assertCreated();

        $this->assertDatabaseCount('meeting_series', 3);
    }

    public function test_update_cancel_and_split_authorization_is_enforced_by_the_http_backend(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);
        $unrelatedTeacher = $this->roleUser('Teacher');
        $student = $this->student($class);
        $admin = $this->roleUser('Admin');
        $superAdmin = $this->roleUser('Super Admin');

        $teacherSeries = $this->series($class, $teacher);
        $this->actingAs($teacher)->patchJson(
            route('meeting-series.update', [$class, $teacherSeries]),
            $this->payload(['title' => 'Teacher updated', 'lifecycle_version' => 0]),
        )->assertOk()->assertJsonPath('series.title', 'Teacher updated');

        $protectedSeries = $this->series($class, $teacher, ['title' => 'Protected']);
        $this->actingAs($unrelatedTeacher)->patchJson(
            route('meeting-series.update', [$class, $protectedSeries]),
            $this->payload(['lifecycle_version' => 0]),
        )->assertForbidden();
        $this->actingAs($student)->postJson(
            route('meeting-series.cancel', [$class, $protectedSeries]),
            ['scope' => 'entire', 'lifecycle_version' => 0],
        )->assertForbidden();
        $this->actingAs($admin)->postJson(
            route('meeting-series.cancel', [$class, $protectedSeries]),
            ['scope' => 'entire', 'lifecycle_version' => 0],
        )->assertOk();
        $this->assertSame(MeetingSeriesStatus::Cancelled, $protectedSeries->fresh()->status);

        $splitSeries = $this->series($class, $teacher, ['title' => 'Split source']);
        $this->actingAs($unrelatedTeacher)->postJson(
            route('meeting-series.split', [$class, $splitSeries]),
            $this->payload([
                'starts_on' => '2026-09-27',
                'cutoff_on' => '2026-09-27',
                'lifecycle_version' => 0,
            ]),
        )->assertForbidden();
        $this->actingAs($superAdmin)->postJson(
            route('meeting-series.split', [$class, $splitSeries]),
            $this->payload([
                'title' => 'Split target',
                'host_user_id' => $teacher->id,
                'starts_on' => '2026-09-27',
                'cutoff_on' => '2026-09-27',
                'lifecycle_version' => 0,
            ]),
        )->assertCreated()->assertJsonPath('series.title', 'Split target');
    }

    public function test_series_definition_validation_rejects_invalid_contract_values(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $cases = [
            'invalid timezone' => [['timezone' => 'Mars/Olympus'], ['timezone']],
            'unsupported recurrence type' => [['recurrence_type' => 'fortnightly'], ['recurrence_type']],
            'selected weekdays without weekdays' => [['recurrence_type' => 'selected_weekdays', 'weekdays' => null], ['weekdays']],
            'weekday outside range' => [['recurrence_type' => 'selected_weekdays', 'weekdays' => [1, 8]], ['weekdays.1']],
            'end before start' => [['ends_on' => '2026-09-24'], ['ends_on']],
            'malformed date' => [['starts_on' => '25/09/2026'], ['starts_on']],
            'malformed local time' => [['local_start_time' => '9am'], ['local_start_time']],
            'invalid duration' => [['duration_minutes' => 0], ['duration_minutes']],
            'invalid capacity' => [['max_participants' => 1], ['max_participants']],
        ];

        foreach ($cases as $description => [$overrides, $errors]) {
            $this->actingAs($teacher)->postJson(
                route('meeting-series.store', $class),
                $this->payload($overrides),
            )->assertUnprocessable()->assertJsonValidationErrors($errors);
        }

        $this->assertDatabaseCount('meeting_series', 0);
    }

    public function test_null_ends_on_is_accepted_and_returned_as_null(): void
    {
        $class = $this->activeClass();
        $teacher = $this->classTeacher($class);

        $this->actingAs($teacher)->postJson(
            route('meeting-series.store', $class),
            $this->payload(['ends_on' => null]),
        )->assertCreated()->assertJsonPath('series.ends_on', null);

        $series = MeetingSeries::query()->sole();
        $this->assertNull($series->ends_on);
        $this->assertSame(366, $series->meetings()->count());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Recurring lesson',
            'description' => 'Phase C test',
            'class_subject_id' => null,
            'host_user_id' => null,
            'recurrence_type' => MeetingRecurrenceType::Daily->value,
            'weekdays' => null,
            'starts_on' => '2026-09-25',
            'ends_on' => '2026-09-29',
            'local_start_time' => '09:00',
            'duration_minutes' => 60,
            'timezone' => 'Asia/Phnom_Penh',
            'max_participants' => 50,
        ], $overrides);
    }

    private function activeClass(): SchoolClass
    {
        $year = AcademicYear::factory()->create([
            'name' => '2026/2028',
            'starts_on' => '2026-01-01',
            'ends_on' => '2028-12-31',
            'status' => AcademicYearStatus::Active,
            'active_slot' => 1,
        ]);

        return SchoolClass::factory()->create([
            'academic_year_id' => $year->id,
            'status' => SchoolClassStatus::Active,
        ]);
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function classTeacher(SchoolClass $class): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create([
            'teacher_profile_id' => $profile->id,
            'school_class_id' => $class->id,
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'current_slot' => 1,
        ]);

        return $user;
    }

    private function student(SchoolClass $class): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class->id,
            'enrolled_on' => '2026-01-01',
            'current_slot' => 1,
        ]);

        return $user;
    }

    private function series(SchoolClass $class, User $host, array $overrides = []): MeetingSeries
    {
        return MeetingSeries::factory()->create(array_merge([
            'school_class_id' => $class->id,
            'created_by' => $host->id,
            'host_user_id' => $host->id,
            'recurrence_type' => MeetingRecurrenceType::Daily,
            'starts_on' => '2026-09-25',
            'ends_on' => '2026-09-29',
            'timezone' => 'Asia/Phnom_Penh',
        ], $overrides));
    }
}
