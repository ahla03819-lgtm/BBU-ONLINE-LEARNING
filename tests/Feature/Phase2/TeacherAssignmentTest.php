<?php

namespace Tests\Feature\Phase2;

use App\Actions\People\AssignTeacherToClass;
use App\Actions\People\AssignTeacherToClassSubject;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_teacher_reassignment_preserves_the_old_assignment(): void
    {
        $class = SchoolClass::factory()->create();
        $first = TeacherProfile::factory()->create();
        $second = TeacherProfile::factory()->create();
        $old = app(AssignTeacherToClass::class)->handle($first, $class, '2026-09-01');
        $new = app(AssignTeacherToClass::class)->handle($second, $class, '2026-10-01');

        $this->assertNull($old->fresh()->current_slot);
        $this->assertSame('2026-10-01', $old->fresh()->ends_on->toDateString());
        $this->assertSame(1, $new->current_slot);
        $this->assertSame(2, TeacherClassAssignment::count());
    }

    public function test_class_subject_teacher_reassignment_preserves_history(): void
    {
        $subject = ClassSubject::factory()->create();
        $first = TeacherProfile::factory()->create();
        $second = TeacherProfile::factory()->create();
        $old = app(AssignTeacherToClassSubject::class)->handle($first, $subject, '2026-09-01');
        $new = app(AssignTeacherToClassSubject::class)->handle($second, $subject, '2026-10-01');

        $this->assertNull($old->fresh()->current_slot);
        $this->assertSame(1, $new->current_slot);
        $this->assertSame(2, TeacherClassSubjectAssignment::count());
    }
}
