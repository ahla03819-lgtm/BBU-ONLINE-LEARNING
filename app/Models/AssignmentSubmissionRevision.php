<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Database\Factories\AssignmentSubmissionRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['assignment_submission_id', 'revision_number', 'client_uuid', 'authored_by', 'status', 'draft_slot', 'body', 'submitted_at', 'is_late', 'lock_version'])]
class AssignmentSubmissionRevision extends Model
{
    /** @use HasFactory<AssignmentSubmissionRevisionFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'draft', 'draft_slot' => 1, 'lock_version' => 0];

    protected function casts(): array
    {
        return ['status' => SubmissionStatus::class, 'submitted_at' => 'datetime', 'is_late' => 'boolean'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'assignment_submission_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AssignmentSubmissionAttachment::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(AssignmentGrade::class);
    }
}
