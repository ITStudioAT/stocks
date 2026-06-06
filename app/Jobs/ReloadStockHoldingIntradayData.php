<?php

namespace App\Jobs;

use App\Services\StockHoldingIntradayDataReloader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReloadStockHoldingIntradayData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $refreshId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(StockHoldingIntradayDataReloader $reloader): void
    {
        $reloader->import($this->refreshId);
    }

    public function failed(?Throwable $exception): void
    {
        app(StockHoldingIntradayDataReloader::class)->fail(
            $this->refreshId,
            $exception?->getMessage() ?? 'Unknown queue failure.',
        );
    }
}
