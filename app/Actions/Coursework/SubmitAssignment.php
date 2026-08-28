<?php

namespace App\Actions\Coursework;

use App\Enums\SubmissionStatus;
use App\Events\AssignmentSubmitted;
use App\Models\AssignmentSubmission;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SubmitAssignment
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $student, AssignmentSubmission $submission): AssignmentSubmission
    {
        Gate::forUser($student)->authorize('submit', $submission);

        return DB::transaction(function () use ($student, $submission) {
            $locked = AssignmentSubmission::query()->lockForUpdate()->with('assignment')->findOrFail($submission->id);
            Gate::forUser($student)->authorize('submit', $locked);
            $draft = $locked->revisions()->where('draft_slot', 1)->lockForUpdate()->first();
            if (! $draft) {
                throw ValidationException::withMessages(['submission' => 'No draft is available to submit.']);
            }
            if (trim((string) $draft->body) === '' && ! $draft->attachments()->exists()) {
                throw ValidationException::withMessages(['submission' => 'Add text or at least one attachment before submitting.']);
            }
            $submittedAt = now();
            $isLate = $locked->assignment->due_at ? $submittedAt->greaterThan($locked->assignment->due_at) : false;
            $draft->update(['status' => SubmissionStatus::Submitted, 'draft_slot' => null, 'submitted_at' => $submittedAt, 'is_late' => $isLate, 'lock_version' => $draft->lock_version + 1]);
            $locked->update(['status' => SubmissionStatus::Submitted, 'last_submitted_at' => $submittedAt, 'lock_version' => $locked->lock_version + 1]);
            $this->audit->log('submission.submitted', $locked, [], ['assignment_id' => $locked->assignment_id, 'revision_number' => $draft->revision_number, 'submitted_at' => $submittedAt, 'is_late' => $isLate]);
            AssignmentSubmitted::dispatch($draft);

            return $locked->refresh();
        });
    }
}
