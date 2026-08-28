<?php

namespace App\Actions\Coursework;

use App\Enums\SubmissionStatus;
use App\Models\AssignmentSubmission;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartResubmission
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $student, AssignmentSubmission $submission): AssignmentSubmission
    {
        Gate::forUser($student)->authorize('update', $submission);

        return DB::transaction(function () use ($student, $submission) {
            $locked = AssignmentSubmission::query()->lockForUpdate()->with('assignment')->findOrFail($submission->id);
            Gate::forUser($student)->authorize('update', $locked);
            if (! $locked->assignment->allow_resubmission || $locked->status !== SubmissionStatus::Submitted || $locked->revisions()->where('draft_slot', 1)->exists()) {
                throw ValidationException::withMessages(['submission' => 'This submission cannot be resubmitted.']);
            }
            $number = $locked->latest_revision_number + 1;
            $locked->revisions()->create(['revision_number' => $number, 'client_uuid' => Str::uuid(), 'authored_by' => $student->id, 'status' => SubmissionStatus::Draft, 'draft_slot' => 1]);
            $locked->update(['status' => SubmissionStatus::Draft, 'latest_revision_number' => $number, 'lock_version' => $locked->lock_version + 1]);
            $this->audit->log('submission.resubmission-started', $locked, [], ['revision_number' => $number]);

            return $locked->refresh();
        });
    }
}
