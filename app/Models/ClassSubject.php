<?php

namespace App\Models;

use App\Enums\ClassSubjectStatus;
use Database\Factories\ClassSubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['school_class_id', 'subject_id', 'status', 'archived_at', 'archived_by'])]
class ClassSubject extends Model
{
    /** @use HasFactory<ClassSubjectFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['status' => ClassSubjectStatus::class, 'archived_at' => 'datetime'];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherClassSubjectAssignment::class);
    }

    public function channel(): HasOne
    {
        return $this->hasOne(Channel::class);
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }
}
