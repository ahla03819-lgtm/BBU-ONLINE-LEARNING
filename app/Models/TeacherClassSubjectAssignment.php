<?php

namespace App\Models;

use Database\Factories\TeacherClassSubjectAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['teacher_profile_id', 'class_subject_id', 'starts_on', 'ends_on', 'current_slot'])]
class TeacherClassSubjectAssignment extends Model
{
    /** @use HasFactory<TeacherClassSubjectAssignmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }
}
