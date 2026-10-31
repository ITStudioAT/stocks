<?php

namespace App\Jobs;

use App\Models\IndexWatchItem;
use App\Services\ChartDataRefresh;
use App\Services\IndexWatchItemPriceRefresher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class LoadIndexChartPrices implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $indexId, public ?string $range, public int $priceLimit, public string $refreshKey) {}

    public function uniqueId(): string
    {
        return $this->refreshKey;
    }

    /**
     * Execute the job.
     */
    public function handle(IndexWatchItemPriceRefresher $priceRefresher, ChartDataRefresh $refresh): void
    {
        $item = IndexWatchItem::query()->find($this->indexId);
        if ($item === null) {
            $refresh->finish($this->refreshKey, 'Index no longer exists.');

            return;
        }
        $refresh->running($this->refreshKey);
        $hasPrices = $this->range === 'intraday'
            ? ($priceRefresher->refresh($item) || $item->prices()->exists())
            : $priceRefresher->ensureRecentPrices($item, $this->priceLimit);
        if (! $hasPrices && ! $item->prices()->exists()) {
            throw new RuntimeException('Index prices could not be retrieved from EODHD.');
        }
        $refresh->finish($this->refreshKey);
    }

    public function failed(?Throwable $exception): void
    {
        app(ChartDataRefresh::class)->finish($this->refreshKey, $exception?->getMessage() ?? 'Index refresh failed.');
    }
}
