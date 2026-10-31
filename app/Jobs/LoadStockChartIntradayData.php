<?php

namespace App\Jobs;

use App\Models\StockHolding;
use App\Services\ChartDataRefresh;
use App\Services\EodhdMarketData;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class LoadStockChartIntradayData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $holdingId, public string $kind, public string $refreshKey) {}

    public function uniqueId(): string
    {
        return $this->refreshKey;
    }

    /**
     * Execute the job.
     */
    public function handle(EodhdMarketData $marketData, ChartDataRefresh $refresh): void
    {
        $holding = StockHolding::query()->find($this->holdingId);
        if ($holding === null) {
            $refresh->finish($this->refreshKey, 'Stock no longer exists.');

            return;
        }

        $refresh->running($this->refreshKey);
        if ($this->kind === 'detail') {
            $marketData->ensureLastSevenTradingDayFiveMinuteCandles($holding);
        } else {
            $marketData->ensureIntradaySamples($holding);
        }
        $refresh->finish($this->refreshKey);
    }

    public function failed(?Throwable $exception): void
    {
        app(ChartDataRefresh::class)->finish($this->refreshKey, $exception?->getMessage() ?? 'Intraday refresh failed.');
    }
}
