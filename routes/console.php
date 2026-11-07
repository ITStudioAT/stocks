<?php

use App\Console\Commands\RedactEodhdErrors;
use App\Jobs\RefreshPrivateIndexQuotes;
use App\Models\AdminLoginCode;
use App\Services\EodhdApiClient;
use App\Services\MarketPriceStore;
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
$canRunEodhd = static fn (): bool => $canRun() && ! config('services.market_data.free_only', true) && app(EodhdApiClient::class)->configured();

Schedule::command('dashboard:backfill-performance')->dailyAt('00:20')
    ->when($canRun)->withoutOverlapping(120);

Schedule::job(new RefreshPrivateIndexQuotes)->everyFifteenMinutes()
    ->when(static fn (): bool => ! $preview && config('queue.default') !== 'sync');

Schedule::command('market-data:collect')->everyFiveMinutes()
    ->when(static fn (): bool => ! $preview && MarketPriceStore::enabled())->withoutOverlapping(5);
Schedule::command('market-data:daily --days=7 --limit=3 --initialized-only')->hourly()
    ->when(static fn (): bool => ! $preview && MarketPriceStore::enabled())->withoutOverlapping(10);

Schedule::command('intraday:collect-free --queue')
    ->everyMinute()
    ->weekdays()
    ->timezone('Europe/Vienna')
    ->between('08:15', '23:00')
    ->when(static fn (): bool => ! $preview && ! MarketPriceStore::enabled())
    ->withoutOverlapping(2);

Schedule::command('price-refresh:dispatch-due')
    ->everyMinute()
    ->when(static fn (): bool => $canRun() && ! MarketPriceStore::enabled())
    ->withoutOverlapping(10);

Schedule::command('intraday-candles:dispatch-due')
    ->everyMinute()
    ->when(static fn (): bool => $canRun() && ! MarketPriceStore::enabled())
    ->withoutOverlapping(10);

Schedule::command('end-of-day-data:dispatch-due')
    ->everyMinute()
    ->when($canRunEodhd)
    ->withoutOverlapping(10);

Schedule::command('indices-data:dispatch-due')
    ->everyMinute()
    ->when($canRunEodhd)
    ->withoutOverlapping(10);

Schedule::command('indices:eodhd-sync:dispatch-due')
    ->everyMinute()
    ->when($canRunEodhd)
    ->withoutOverlapping(30);

Schedule::command('indices:v2-realtime:dispatch-due')
    ->everyMinute()
    ->when($canRunEodhd)
    ->withoutOverlapping(10);

Schedule::command('model:prune', ['--model' => [AdminLoginCode::class]])
    ->dailyAt('03:15')
    ->when($canRun)
    ->withoutOverlapping();
