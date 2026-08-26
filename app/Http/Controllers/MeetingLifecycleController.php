<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\EndMeeting;
use App\Actions\Meetings\RetryMeetingReconciliation;
use App\Actions\Meetings\StartMeeting;
use App\Http\Requests\Meetings\EndMeetingRequest;
use App\Http\Requests\Meetings\ReconcileMeetingRequest;
use App\Http\Requests\Meetings\StartMeetingRequest;
use App\Models\Meeting;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;

class MeetingLifecycleController extends Controller
{
    public function start(StartMeetingRequest $request, SchoolClass $schoolClass, Meeting $meeting, StartMeeting $action): RedirectResponse
    {
        $action->handle($request->user(), $meeting);

        return back()->with('success', 'Meeting start request processed.');
    }

    public function end(EndMeetingRequest $request, SchoolClass $schoolClass, Meeting $meeting, EndMeeting $action): RedirectResponse
    {
        $action->handle($request->user(), $meeting);

        return back()->with('success', 'Meeting end request processed.');
    }

    public function reconcile(ReconcileMeetingRequest $request, SchoolClass $schoolClass, Meeting $meeting, RetryMeetingReconciliation $action): RedirectResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        $action->handle($request->user(), $meeting, $request->integer('lifecycle_version'));

        return back()->with('success', 'Meeting reconciliation checked against the provider.');
    }
}
