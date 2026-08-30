<?php

namespace App\Http\Controllers;

use App\Services\DashboardData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardData $dashboard): Response
    {
        return Inertia::render('Dashboard', $dashboard->for($request->user()));
    }
}
