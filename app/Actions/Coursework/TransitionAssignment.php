<?php

namespace App\Actions\Coursework;

use App\Enums\AssignmentStatus;
use App\Events\AssignmentLifecycleChanged;
use App\Events\AssignmentPublished;
use App\Models\Assignment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CourseworkAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransitionAssignment
{
    public function __construct(private AuditLogger $audit, private CourseworkAccess $access) {}

    public function handle(User $actor, Assignment $assignment, string $ability, int $version): Assignment
    {
        Gate::forUser($actor)->authorize($ability, $assignment);

        return DB::transaction(function () use ($actor, $assignment, $ability, $version) {
            $locked = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);
            Gate::forUser($actor)->authorize($ability, $locked);
            if ($locked->lifecycle_version !== $version) {
                throw ValidationException::withMessages(['lifecycle_version' => 'This assignment changed. Refresh and try again.']);
            }
            $before = ['status' => $locked->status->value, 'lifecycle_version' => $locked->lifecycle_version];
            $now = now();
            match ($ability) {
                'publish' => $locked->fill(['status' => AssignmentStatus::Published, 'published_at' => $now]),
                'close' => $locked->fill(['status' => AssignmentStatus::Closed, 'closed_at' => $now]),
                'archive' => $locked->fill(['status' => AssignmentStatus::Archived, 'archived_from_status' => $locked->status->value, 'archived_at' => $now, 'archived_by' => $actor->id]),
                'restore' => $locked->fill($this->restoreValues($actor, $locked)),
                default => throw ValidationException::withMessages(['status' => 'Unsupported assignment transition.']),
            };
            $locked->lifecycle_version++;
            $locked->save();
            $this->audit->log('assignment.'.$ability.'d', $locked, $before, ['status' => $locked->status->value, 'lifecycle_version' => $locked->lifecycle_version]);
            AssignmentLifecycleChanged::dispatch($locked, $ability);
            if ($ability === 'publish') {
                AssignmentPublished::dispatch($locked);
            }

            return $locked;
        });
    }

    private function restoreValues(User $actor, Assignment $assignment): array
    {
        $target = AssignmentStatus::tryFrom((string) $assignment->archived_from_status) ?? AssignmentStatus::Closed;
        if ($target === AssignmentStatus::Published && ! $this->access->canMutateAssignment($actor, $assignment)) {
            $target = AssignmentStatus::Closed;
        }

        return ['status' => $target, 'archived_at' => null, 'archived_by' => null, 'archived_from_status' => null];
    }
}
