<?php

namespace App\Jobs;

use App\Services\StockHoldingIntradayDataReloader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class BackfillMissingStockHoldingIntradayCandles implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public string $refreshId,
    ) {}

    public function handle(StockHoldingIntradayDataReloader $reloader): void
    {
        $reloader->importMissingYear($this->refreshId);
    }

    public function failed(?Throwable $exception): void
    {
        app(StockHoldingIntradayDataReloader::class)->fail(
            $this->refreshId,
            $exception?->getMessage() ?? 'Unknown queue failure.',
        );
    }
}
