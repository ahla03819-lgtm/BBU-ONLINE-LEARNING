<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Database\Factories\AssignmentSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['assignment_id', 'student_profile_id', 'status', 'latest_revision_number', 'last_submitted_at', 'lock_version'])]
class AssignmentSubmission extends Model
{
    /** @use HasFactory<AssignmentSubmissionFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'draft', 'latest_revision_number' => 0, 'lock_version' => 0];

    protected function casts(): array
    {
        return ['status' => SubmissionStatus::class, 'last_submitted_at' => 'datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AssignmentSubmissionRevision::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(AssignmentGrade::class);
    }

    public function currentDraft(): ?AssignmentSubmissionRevision
    {
        return $this->revisions()->where('draft_slot', 1)->first();
    }

    public function latestSubmitted(): ?AssignmentSubmissionRevision
    {
        return $this->revisions()->where('status', SubmissionStatus::Submitted->value)->latest('revision_number')->first();
    }

    public function latestGrade(): ?AssignmentGrade
    {
        return $this->grades()->latest('revision_number')->first();
    }
}
