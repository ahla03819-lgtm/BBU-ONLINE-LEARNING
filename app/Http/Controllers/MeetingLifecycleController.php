<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\EndMeeting;
use App\Actions\Meetings\StartMeeting;
use App\Http\Requests\Meetings\EndMeetingRequest;
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
}
