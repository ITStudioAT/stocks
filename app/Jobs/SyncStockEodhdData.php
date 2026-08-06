<?php

namespace App\Jobs;

use App\Services\V2StockEodhdSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncStockEodhdData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public int $uniqueFor = 7500;

    public function __construct(public string $refreshId) {}

    public function uniqueId(): string
    {
        return 'eodhd-v2-stocks-sync';
    }

    public function handle(V2StockEodhdSyncService $syncService): void
    {
        $syncService->run($this->refreshId);
    }

    public function failed(?Throwable $exception): void
    {
        app(V2StockEodhdSyncService::class)->fail(
            $this->refreshId,
            $exception?->getMessage() ?? 'Unknown queue failure.',
        );
    }
}
