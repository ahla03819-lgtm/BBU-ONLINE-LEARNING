<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalendarRangeRequest;
use App\Services\CalendarData;
use Illuminate\Http\JsonResponse;

class CalendarController extends Controller
{
    public function __invoke(CalendarRangeRequest $request, CalendarData $calendar): JsonResponse
    {
        $start = $request->startBoundary();
        $end = $request->endBoundary();

        return response()->json([
            'view' => $request->validated('view'),
            'range' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
            'timezone' => config('calendar.default_timezone'),
            'events' => $calendar->for($request->user(), $start, $end),
        ]);
    }
}
