<?php

namespace App\Models;

use Database\Factories\TeacherProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'employee_number', 'phone', 'hired_on', 'notes'])]
class TeacherProfile extends Model
{
    /** @use HasFactory<TeacherProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['hired_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function classAssignments(): HasMany
    {
        return $this->hasMany(TeacherClassAssignment::class);
    }

    public function classSubjectAssignments(): HasMany
    {
        return $this->hasMany(TeacherClassSubjectAssignment::class);
    }
}
