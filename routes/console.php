<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('announcements:publish-due')->everyMinute()->withoutOverlapping();
Schedule::command('meetings:reconcile-webhooks')->everyMinute()->withoutOverlapping();
Schedule::command('meetings:reconcile-recordings')->everyMinute()->withoutOverlapping();
Schedule::command('meetings:reconcile-lifecycle')->everyMinute()->withoutOverlapping();
Schedule::command('meetings:replenish-series')->daily()->withoutOverlapping();
