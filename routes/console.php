<?php

use App\Console\Commands\RedactEodhdErrors;
use App\Models\AdminLoginCode;
use App\Services\PreviewBackgroundState;
use App\Services\PreviewIsolation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::addCommands([RedactEodhdErrors::class]);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$preview = app(PreviewIsolation::class)->active();
if ($preview && config('security.preview.control_enabled') !== true) {
    return;
}
$canRun = static fn (): bool => ! $preview || app(PreviewBackgroundState::class)->enabled();

Schedule::command('price-refresh:dispatch-due')
    ->everyMinute()
    ->when($canRun)
    ->withoutOverlapping(10);

Schedule::command('intraday-candles:dispatch-due')
    ->everyMinute()
    ->when($canRun)
    ->withoutOverlapping(10);

Schedule::command('end-of-day-data:dispatch-due')
    ->everyMinute()
    ->when($canRun)
    ->withoutOverlapping(10);

Schedule::command('indices-data:dispatch-due')
    ->everyMinute()
    ->when($canRun)
    ->withoutOverlapping(10);

Schedule::command('indices:eodhd-sync:dispatch-due')
    ->everyMinute()
    ->when($canRun)
    ->withoutOverlapping(30);

Schedule::command('indices:v2-realtime:dispatch-due')
    ->everyMinute()
    ->when($canRun)
    ->withoutOverlapping(10);

Schedule::command('model:prune', ['--model' => [AdminLoginCode::class]])
    ->dailyAt('03:15')
    ->when($canRun)
    ->withoutOverlapping();
