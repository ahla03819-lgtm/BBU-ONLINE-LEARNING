<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['class_subject_id', 'created_by', 'title', 'instructions', 'max_points', 'due_at', 'allow_resubmission', 'status', 'published_at', 'closed_at', 'archived_at', 'archived_by', 'archived_from_status', 'lifecycle_version'])]
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'draft', 'allow_resubmission' => true, 'lifecycle_version' => 0];

    protected function casts(): array
    {
        return ['status' => AssignmentStatus::class, 'max_points' => 'decimal:2', 'allow_resubmission' => 'boolean', 'due_at' => 'datetime', 'published_at' => 'datetime', 'closed_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}
