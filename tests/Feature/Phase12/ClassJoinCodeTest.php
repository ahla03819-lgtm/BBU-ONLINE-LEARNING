<?php

namespace Tests\Feature\Phase12;

use App\Enums\AcademicYearStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassJoinCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_authorized_administrator_can_create_a_class_with_a_server_generated_join_code(): void
    {
        $admin = $this->user('Admin');
        $year = AcademicYear::factory()->active()->create();
        $grade = GradeLevel::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->post(route('school-classes.store'), [
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'Foundation A',
            'section' => 'A',
            'status' => SchoolClassStatus::Active->value,
            'capacity' => 25,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $class = SchoolClass::query()->where('name', 'Foundation A')->firstOrFail();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{3}-[A-Z0-9]{3}$/', $class->join_code);
        $this->assertTrue($class->join_code_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'school-class.created', 'actor_id' => $admin->id]);
    }

    public function test_unauthorized_user_cannot_create_a_class(): void
    {
        $student = $this->student();
        $class = $this->activeClass();

        $this->actingAs($student->user)->post(route('school-classes.store'), [
            'academic_year_id' => $class->academic_year_id,
            'grade_level_id' => $class->grade_level_id,
            'name' => 'Forged class',
            'status' => SchoolClassStatus::Active->value,
        ])->assertForbidden();
    }

    public function test_eligible_student_can_join_an_active_class_with_a_normalized_code_and_open_its_workspace(): void
    {
        $class = $this->joinableClass('AB7-K9Q');
        $student = $this->student();

        $this->actingAs($student->user)->post(route('classes.join'), ['code' => ' ab7 k9q '])
            ->assertRedirect(route('classes.show', $class));

        $this->assertDatabaseHas('enrollments', ['student_profile_id' => $student->id, 'school_class_id' => $class->id, 'academic_year_id' => $class->academic_year_id, 'current_slot' => 1]);
        $this->actingAs($student->user)->get(route('classes.show', $class))->assertOk();
    }

    public function test_join_rejects_invalid_disabled_closed_and_duplicate_codes_but_allows_another_class_in_the_same_year(): void
    {
        $student = $this->student();
        $disabled = $this->joinableClass('AAA-111', ['join_code_enabled' => false]);
        $closed = $this->joinableClass('BBB-222', ['status' => SchoolClassStatus::Closed]);

        $this->actingAs($student->user)->post(route('classes.join'), ['code' => 'CCC-333'])->assertSessionHasErrors('code');
        $this->actingAs($student->user)->post(route('classes.join'), ['code' => $disabled->join_code])->assertSessionHasErrors('code');
        $this->actingAs($student->user)->post(route('classes.join'), ['code' => $closed->join_code])->assertSessionHasErrors('code');

        $same = $this->joinableClass('DDD-444');
        Enrollment::factory()->create(['student_profile_id' => $student->id, 'academic_year_id' => $same->academic_year_id, 'school_class_id' => $same->id, 'current_slot' => 1]);
        $this->actingAs($student->user)->post(route('classes.join'), ['code' => $same->join_code])->assertSessionHasErrors('code');

        $target = $this->joinableClass('EEE-555', $same->academicYear->only('id'));
        $this->actingAs($student->user)->post(route('classes.join'), ['code' => $target->join_code])
            ->assertRedirect(route('classes.show', $target));
        $this->assertSame(2, Enrollment::query()->where('student_profile_id', $student->id)->where('current_slot', 1)->count());
        $this->actingAs($student->user)->get(route('classes.show', $same))->assertOk();
        $this->actingAs($student->user)->get(route('classes.show', $target))->assertOk();
    }

    public function test_only_eligible_student_accounts_can_join_and_requests_cannot_forge_another_student(): void
    {
        $class = $this->joinableClass('FFF-666');
        $teacher = $this->user('Teacher');
        $student = $this->student();
        $other = $this->student();

        $this->actingAs($teacher)->post(route('classes.join'), ['code' => $class->join_code])->assertForbidden();
        $this->actingAs($student->user)->post(route('classes.join'), ['code' => $class->join_code, 'student_profile_id' => $other->id])->assertSessionHasErrors('student_profile_id');
        $this->assertDatabaseMissing('enrollments', ['student_profile_id' => $other->id, 'school_class_id' => $class->id]);
    }

    public function test_authorized_manager_can_view_regenerate_and_disable_a_join_code(): void
    {
        $admin = $this->user('Admin');
        $class = $this->joinableClass('GGG-777');

        $this->actingAs($admin)->get(route('classes.show', $class))->assertOk()->assertInertia(fn ($page) => $page
            ->where('schoolClass.joinCode.code', 'GGG-777')
            ->where('schoolClass.joinCode.enabled', true));
        $this->actingAs($admin)->patch(route('school-classes.join-code.update', $class), ['action' => 'regenerate'])->assertRedirect();
        $newCode = $class->fresh()->join_code;
        $this->assertNotSame('GGG-777', $newCode);
        $this->assertTrue($class->fresh()->join_code_enabled);

        $oldCodeStudent = $this->student();
        $this->actingAs($oldCodeStudent->user)->post(route('classes.join'), ['code' => 'GGG-777'])->assertSessionHasErrors('code');
        $newCodeStudent = $this->student();
        $this->actingAs($newCodeStudent->user)->post(route('classes.join'), ['code' => $newCode])->assertRedirect(route('classes.show', $class));

        $this->actingAs($admin)->patch(route('school-classes.join-code.update', $class), ['action' => 'disable'])->assertRedirect();
        $this->assertFalse($class->fresh()->join_code_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'school-class.join-code.disabled', 'actor_id' => $admin->id]);
    }

    public function test_students_cannot_manage_another_class_join_code(): void
    {
        $class = $this->joinableClass('HHH-888');
        $student = $this->student();

        $this->actingAs($student->user)->patch(route('school-classes.join-code.update', $class), ['action' => 'disable'])->assertForbidden();
        $this->assertTrue($class->fresh()->join_code_enabled);
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }

    private function student(): StudentProfile
    {
        $user = $this->user('Student');

        return StudentProfile::factory()->create(['user_id' => $user->id]);
    }

    private function activeClass(?AcademicYear $year = null): SchoolClass
    {
        $year ??= AcademicYear::query()->where('status', AcademicYearStatus::Active->value)->first()
            ?? AcademicYear::factory()->create(['status' => AcademicYearStatus::Active, 'active_slot' => 1]);

        return SchoolClass::factory()->create(['academic_year_id' => $year->id, 'status' => SchoolClassStatus::Active]);
    }

    private function joinableClass(string $code, array $overrides = []): SchoolClass
    {
        $year = $overrides['id'] ?? null ? AcademicYear::findOrFail($overrides['id']) : null;
        $class = $this->activeClass($year);
        $class->forceFill(array_merge(['join_code' => $code, 'join_code_enabled' => true], array_diff_key($overrides, ['id' => true])))->save();

        return $class;
    }
}
