<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('price-refresh:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('intraday-candles:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('end-of-day-data:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('indices-data:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(10);
