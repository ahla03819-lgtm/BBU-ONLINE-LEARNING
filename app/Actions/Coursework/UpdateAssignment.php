<?php

namespace App\Actions\Coursework;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateAssignment
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, Assignment $assignment, array $data): Assignment
    {
        Gate::forUser($actor)->authorize('update', $assignment);

        return DB::transaction(function () use ($actor, $assignment, $data) {
            $locked = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);
            Gate::forUser($actor)->authorize('update', $locked);
            if ((int) $data['lifecycle_version'] !== $locked->lifecycle_version) {
                throw ValidationException::withMessages(['lifecycle_version' => 'This assignment changed. Refresh and try again.']);
            }
            $before = $locked->only('title', 'instructions', 'max_points', 'due_at', 'allow_resubmission');
            $allowed = $locked->status === AssignmentStatus::Draft
                ? ['title', 'instructions', 'max_points', 'due_at', 'allow_resubmission']
                : ['title', 'instructions', 'due_at'];
            $locked->fill(collect($data)->only($allowed)->all());
            $locked->increment('lifecycle_version');
            $locked->refresh();
            $this->audit->log($locked->status === AssignmentStatus::Published ? 'assignment.published-corrected' : 'assignment.updated', $locked, $before, $locked->only($allowed));

            return $locked;
        });
    }
}
