<?php

namespace App\Models;

use App\Enums\SchoolClassStatus;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['academic_year_id', 'grade_level_id', 'name', 'section', 'status', 'capacity', 'join_code', 'join_code_enabled', 'cover_image_path'])]
class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use HasFactory;

    protected $hidden = ['cover_image_path'];

    protected function casts(): array
    {
        return ['status' => SchoolClassStatus::class, 'join_code_enabled' => 'boolean'];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherClassAssignment::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function meetingSeries(): HasMany
    {
        return $this->hasMany(MeetingSeries::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function attendanceRegisters(): HasMany
    {
        return $this->hasMany(AttendanceRegister::class);
    }

    public function coverImageUrl(): ?string
    {
        return $this->cover_image_path && str_starts_with($this->cover_image_path, "class-covers/{$this->id}/") && Storage::disk('public')->exists($this->cover_image_path)
            ? Storage::disk('public')->url($this->cover_image_path) : null;
    }
}
