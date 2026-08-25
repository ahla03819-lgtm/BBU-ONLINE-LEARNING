<?php

namespace App\Models;

use Database\Factories\TeacherClassAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['teacher_profile_id', 'school_class_id', 'starts_on', 'ends_on', 'current_slot'])]
class TeacherClassAssignment extends Model
{
    /** @use HasFactory<TeacherClassAssignmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }
}
