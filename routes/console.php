<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('devices:poll')->everyMinute()->withoutOverlapping();
Schedule::command('devices:poll-snmp')->everyFiveMinutes()->withoutOverlapping();
