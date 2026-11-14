<?php

use App\Console\Commands\RedactEodhdErrors;
use App\Models\AdminLoginCode;
use App\Services\EodhdApiClient;
use App\Services\MarketPriceStore;
use App\Services\PrivateIndexQuotes;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::addCommands([RedactEodhdErrors::class]);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$canRunEodhd = static fn (): bool => ! config('services.market_data.free_only', true) && app(EodhdApiClient::class)->configured();

Schedule::command('dashboard:backfill-performance')->dailyAt('00:20')
    ->withoutOverlapping(120);

Schedule::call(fn () => app(PrivateIndexQuotes::class)->queueRefresh())->name('private-index-quotes')->everyFifteenMinutes()
    ->when(static fn (): bool => config('queue.default') !== 'sync');

Schedule::command('market-data:collect')->everyFiveMinutes()
    ->when(static fn (): bool => MarketPriceStore::enabled())->withoutOverlapping(5);

Schedule::command('market-data:daily --days=14 --limit=3 --missing-settled')->cron('7,22,37,52 * * * *')
    ->when(static fn (): bool => MarketPriceStore::enabled())->withoutOverlapping(15);

Schedule::command('intraday:collect-free --queue')
    ->everyMinute()
    ->weekdays()
    ->timezone('Europe/Vienna')
    ->between('08:15', '23:00')
    ->when(static fn (): bool => ! MarketPriceStore::enabled())
    ->withoutOverlapping(2);

Schedule::command('price-refresh:dispatch-due')
    ->everyMinute()
    ->when(static fn (): bool => ! MarketPriceStore::enabled())
    ->withoutOverlapping(10);

Schedule::command('intraday-candles:dispatch-due')
    ->everyMinute()
    ->when(static fn (): bool => ! MarketPriceStore::enabled())
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
    ->withoutOverlapping();
