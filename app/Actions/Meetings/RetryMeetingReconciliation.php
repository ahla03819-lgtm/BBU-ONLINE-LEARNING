<?php

namespace App\Actions\Meetings;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RetryMeetingReconciliation
{
    public function __construct(private ReconcileMeetingLifecycle $reconcile) {}

    public function handle(User $actor, Meeting $meeting, int $expectedVersion): Meeting
    {
        Gate::forUser($actor)->authorize('reconcile', $meeting);
        $authoritative = $meeting->fresh();
        Gate::forUser($actor)->authorize('reconcile', $authoritative);

        if ($authoritative->lifecycle_version !== $expectedVersion) {
            throw ValidationException::withMessages(['meeting' => 'The meeting changed. Refresh before retrying reconciliation.']);
        }

        return $this->reconcile->handle($authoritative, false, $expectedVersion);
    }
}
