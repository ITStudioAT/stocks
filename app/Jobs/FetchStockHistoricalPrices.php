<?php

namespace App\Jobs;

use App\Models\StockHistoricalPriceFetchItem;
use App\Models\StockHistoricalPriceFetchRun;
use App\Models\StockHolding;
use App\Services\StockHistoricalDailyPriceFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class FetchStockHistoricalPrices implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public string $refreshId,
    ) {}

    public function handle(StockHistoricalDailyPriceFetcher $fetcher): void
    {
        $run = StockHistoricalPriceFetchRun::query()->find($this->refreshId);

        if (! $run) {
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
        ]);

        StockHistoricalPriceFetchItem::query()
            ->with('stockHolding')
            ->where('fetch_run_id', $run->id)
            ->where('status', 'queued')
            ->orderBy('id')
            ->get()
            ->each(function (StockHistoricalPriceFetchItem $item) use ($fetcher, $run): void {
                $holding = $item->stockHolding;

                if (! $holding) {
                    $this->finishItem($item, $run, 'failed', 0, 'Stock holding no longer exists.');

                    return;
                }

                $run->update([
                    'current' => $this->holdingLabel($holding),
                ]);
                $item->update(['status' => 'running']);

                try {
                    $storedCount = $fetcher->fetch($holding, $item->date_from, $item->date_to);
                    $this->finishItem($item, $run, $storedCount > 0 ? 'success' : 'unavailable', $storedCount);
                } catch (Throwable $exception) {
                    $this->finishItem($item, $run, 'failed', 0, $exception->getMessage());
                }
            });

        $run->refresh();
        $run->update([
            'status' => $this->runStatus($run),
            'current' => null,
            'finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        StockHistoricalPriceFetchRun::query()
            ->whereKey($this->refreshId)
            ->update([
                'status' => 'failed',
                'current' => null,
                'finished_at' => now(),
                'error_summary' => ['message' => $exception?->getMessage() ?? 'Unknown queue failure.'],
            ]);
    }

    private function finishItem(
        StockHistoricalPriceFetchItem $item,
        StockHistoricalPriceFetchRun $run,
        string $status,
        int $storedCount,
        ?string $errorMessage = null,
    ): void {
        $item->update([
            'status' => $status,
            'stored_count' => $storedCount,
            'error_message' => $errorMessage,
        ]);

        $run->increment('processed_count');
        $run->increment(match ($status) {
            'success' => 'success_count',
            'unavailable' => 'unavailable_count',
            default => 'failed_count',
        });
    }

    private function runStatus(StockHistoricalPriceFetchRun $run): string
    {
        if ($run->failed_count === $run->total_count) {
            return 'failed';
        }

        if ($run->success_count === $run->total_count) {
            return 'finished';
        }

        return 'partial';
    }

    private function holdingLabel(StockHolding $holding): string
    {
        return collect([$holding->symbol, $holding->name])
            ->filter()
            ->implode(' ');
    }
}
