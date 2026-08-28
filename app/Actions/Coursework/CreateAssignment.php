<?php

namespace App\Actions\Coursework;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\ClassSubject;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateAssignment
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, ClassSubject $subject, array $data): Assignment
    {
        Gate::forUser($actor)->authorize('create', [Assignment::class, $subject]);

        return DB::transaction(function () use ($actor, $subject, $data) {
            $assignment = Assignment::query()->create([...$data, 'class_subject_id' => $subject->id, 'created_by' => $actor->id, 'status' => AssignmentStatus::Draft, 'lifecycle_version' => 0]);
            $this->audit->log('assignment.created', $assignment, [], $assignment->only('class_subject_id', 'title', 'max_points', 'due_at', 'allow_resubmission', 'status'));

            return $assignment;
        });
    }
}
