<?php

namespace Database\Factories;

use App\Models\ClassSubject;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeacherClassSubjectAssignment> */
class TeacherClassSubjectAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return ['teacher_profile_id' => TeacherProfile::factory(), 'class_subject_id' => ClassSubject::factory(), 'starts_on' => now()->toDateString(), 'ends_on' => null, 'current_slot' => 1];
    }
}
