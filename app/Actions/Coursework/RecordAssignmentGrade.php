<?php

namespace App\Actions\Coursework;

use App\Enums\SubmissionStatus;
use App\Events\AssignmentGraded;
use App\Models\AssignmentGrade;
use App\Models\AssignmentSubmission;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordAssignmentGrade
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $grader, AssignmentSubmission $submission, array $data): AssignmentGrade
    {
        Gate::forUser($grader)->authorize($submission->grades()->exists() ? 'update' : 'create', [AssignmentGrade::class, $submission]);

        return DB::transaction(function () use ($grader, $submission, $data) {
            $locked = AssignmentSubmission::query()->lockForUpdate()->with('assignment')->findOrFail($submission->id);
            Gate::forUser($grader)->authorize($locked->grades()->exists() ? 'update' : 'create', [AssignmentGrade::class, $locked]);
            $revision = $locked->revisions()->where('status', SubmissionStatus::Submitted->value)->latest('revision_number')->first();
            if (! $revision) {
                throw ValidationException::withMessages(['submission' => 'Only submitted work can be graded.']);
            }
            $previous = $locked->grades()->latest('revision_number')->first();
            if ($previous && trim((string) ($data['change_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['change_reason' => 'Explain why this grade is changing.']);
            }
            if ((float) $data['points_awarded'] < 0 || (float) $data['points_awarded'] > (float) $locked->assignment->max_points) {
                throw ValidationException::withMessages(['points_awarded' => 'Points must be between zero and the assignment maximum.']);
            }
            $grade = $locked->grades()->create(['assignment_submission_revision_id' => $revision->id, 'revision_number' => ($previous?->revision_number ?? 0) + 1, 'points_awarded' => $data['points_awarded'], 'max_points_snapshot' => $locked->assignment->max_points, 'feedback' => $data['feedback'] ?? null, 'change_reason' => $previous ? trim($data['change_reason']) : null, 'graded_by' => $grader->id]);
            $this->audit->log($previous ? 'assignment.grade-changed' : 'assignment.grade-recorded', $grade, $previous?->only('points_awarded', 'feedback') ?? [], $grade->only('points_awarded', 'feedback', 'change_reason', 'revision_number'));
            AssignmentGraded::dispatch($grade);

            return $grade;
        });
    }
}
