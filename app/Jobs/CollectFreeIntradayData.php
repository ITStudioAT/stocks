<?php

namespace App\Jobs;

use App\Services\FreeIntradayMarketData;
use App\Services\MarketDataCoordinator;
use App\Services\MarketPriceStore;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CollectFreeIntradayData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 0;

    public int $uniqueFor = 3600;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(FreeIntradayMarketData $marketData): void
    {
        if (MarketPriceStore::enabled()) {
            app(MarketDataCoordinator::class)->collect();

            return;
        }
        $coverage = $marketData->xetraCoverage(advance: true);
        if ($coverage['collector_busy'] ?? false) {
            $this->release(5);

            return;
        }
        $marketData->syncAll();
        if (($coverage['retryable_files'] ?? $coverage['pending_files']) > 0) {
            $this->release($coverage['warnings'] === [] ? 5 : 60);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        report($exception ?? new \RuntimeException('Free intraday collector failed; pending files retained for the next run.'));
    }
}
