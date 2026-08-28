<?php

namespace App\Models;

use Database\Factories\AssignmentGradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assignment_submission_id', 'assignment_submission_revision_id', 'revision_number', 'points_awarded', 'max_points_snapshot', 'feedback', 'change_reason', 'graded_by'])]
class AssignmentGrade extends Model
{
    /** @use HasFactory<AssignmentGradeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['points_awarded' => 'decimal:2', 'max_points_snapshot' => 'decimal:2'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'assignment_submission_id');
    }

    public function submissionRevision(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmissionRevision::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
