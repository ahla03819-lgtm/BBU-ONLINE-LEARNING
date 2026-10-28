<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestMeetingExitController extends Controller
{
    /** A non-sensitive terminal page for a shared authenticated browser. */
    public function show(Request $request): Response
    {
        return Inertia::render('GuestMeetings/Exit', [
            'authenticated' => $request->user() !== null,
            'login_url' => route('login', absolute: false),
            'dashboard_url' => route('dashboard', absolute: false),
        ]);
    }
}
