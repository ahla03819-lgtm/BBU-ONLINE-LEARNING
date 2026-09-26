<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class CalendarPageController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Calendar/Index', [
            'timezone' => config('calendar.default_timezone'),
        ]);
    }
}
