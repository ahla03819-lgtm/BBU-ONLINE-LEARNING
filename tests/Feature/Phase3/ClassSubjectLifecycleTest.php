<?php

namespace Tests\Feature\Phase3;

use App\Actions\Academics\SyncClassSubjects;
use App\Actions\People\AssignTeacherToClassSubject;
use App\Enums\ChannelStatus;
use App\Enums\ClassSubjectStatus;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TeacherProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassSubjectLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_subject_removal_archives_records_and_restoration_reuses_them(): void
    {
        $class = SchoolClass::factory()->create();
        $subject = Subject::factory()->create();
        $sync = app(SyncClassSubjects::class);
        $sync->handle($class, [$subject->id], '2026-09-01');
        $classSubject = ClassSubject::firstOrFail();
        $channel = $classSubject->channel;
        $assignment = app(AssignTeacherToClassSubject::class)->handle(TeacherProfile::factory()->create(), $classSubject, '2026-09-01');
        $sync->handle($class, [], '2026-10-01');
        $this->assertSame(ClassSubjectStatus::Archived, $classSubject->fresh()->status);
        $this->assertSame(ChannelStatus::Archived, $channel->fresh()->status);
        $this->assertNull($assignment->fresh()->current_slot);
        $this->assertSame('2026-10-01', $assignment->fresh()->ends_on->toDateString());
        $sync->handle($class, [$subject->id], '2026-10-15');
        $this->assertSame($classSubject->id, ClassSubject::firstOrFail()->id);
        $this->assertSame(ClassSubjectStatus::Active, $classSubject->fresh()->status);
        $this->assertSame(ChannelStatus::Active, $channel->fresh()->status);
        $this->assertDatabaseCount('teacher_class_subject_assignments',1);
    }
}
