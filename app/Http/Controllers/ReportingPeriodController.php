<?php

namespace App\Http\Controllers;

use App\Actions\Results\SaveReportingPeriod;
use App\Actions\Results\TransitionReportingPeriod;
use App\Enums\ReportingPeriodStatus;
use App\Http\Requests\Results\SaveReportingPeriodRequest;
use App\Http\Requests\Results\TransitionReportingPeriodRequest;
use App\Models\ReportingPeriod;
use Illuminate\Http\RedirectResponse;

class ReportingPeriodController extends Controller
{
    public function store(SaveReportingPeriodRequest $request, SaveReportingPeriod $action): RedirectResponse
    {
        $action->handle(null, $request->validated());

        return back()->with('success', 'Reporting period created.');
    }

    public function update(SaveReportingPeriodRequest $request, ReportingPeriod $reportingPeriod, SaveReportingPeriod $action): RedirectResponse
    {
        $action->handle($reportingPeriod, $request->validated());

        return back()->with('success', 'Reporting period updated.');
    }

    public function transition(TransitionReportingPeriodRequest $request, ReportingPeriod $reportingPeriod, TransitionReportingPeriod $action): RedirectResponse
    {
        $action->handle($reportingPeriod, $request->enum('status', ReportingPeriodStatus::class));

        return back()->with('success', 'Reporting period lifecycle updated.');
    }
}
