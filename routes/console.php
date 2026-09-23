<?php

use App\Console\Commands\RedactEodhdErrors;
use App\Models\AdminLoginCode;
use App\Services\PreviewIsolation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::addCommands([RedactEodhdErrors::class]);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (app(PreviewIsolation::class)->active()) {
    return;
}

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

Schedule::command('indices:eodhd-sync:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(30);

Schedule::command('indices:v2-realtime:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('model:prune', ['--model' => [AdminLoginCode::class]])
    ->dailyAt('03:15')
    ->withoutOverlapping();
