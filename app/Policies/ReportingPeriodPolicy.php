<?php

namespace App\Policies;

use App\Models\ReportingPeriod;
use App\Models\User;

class ReportingPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('reporting-periods.manage') || $user->can('results.view') || $user->can('results.view-own');
    }

    public function view(User $user, ReportingPeriod $period): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('reporting-periods.manage');
    }

    public function update(User $user, ReportingPeriod $period): bool
    {
        return $user->can('reporting-periods.manage') && $period->status->value === 'draft';
    }

    public function transition(User $user, ReportingPeriod $period): bool
    {
        return $user->can('reporting-periods.manage');
    }
}
