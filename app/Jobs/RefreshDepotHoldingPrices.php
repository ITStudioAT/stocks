<?php

namespace App\Jobs;

use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Models\StockPriceRefreshItem;
use App\Models\StockPriceRefreshRun;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\EodhdMarketData;
use App\Services\IndexWatchItemPriceRefresher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshDepotHoldingPrices implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $refreshId,
        public ?int $recipientUserId = null,
        public bool $includeIndexes = true,
    ) {}

    public function handle(
        EodhdMarketData $marketData,
        IndexWatchItemPriceRefresher $indexPriceRefresher,
        DepotHoldingPriceRefreshProgress $progress,
    ): void {
        $progress->markRunning($this->refreshId);
        $run = StockPriceRefreshRun::query()->find($this->refreshId);
        $run?->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        StockHolding::query()
            ->orderBy('id')
            ->eachById(function (StockHolding $holding) use ($marketData, $progress, $run): void {
                $item = StockPriceRefreshItem::query()->create([
                    'refresh_run_id' => $this->refreshId,
                    'stock_holding_id' => $holding->id,
                    'status' => 'running',
                ]);

                try {
                    $result = $marketData->resolve($holding);
                    $selectedStockPriceId = $holding->fresh()->latest_stock_price_id;
                    $status = $this->itemStatus($result->status);

                    $item->update([
                        'status' => $status,
                        'attempted_sources' => collect($result->attemptedSources)->map(fn ($source): array => [
                            'source_key' => $source->sourceKey,
                            'source_url' => $source->url,
                            'parser_key' => $source->parserKey,
                        ])->values()->all(),
                        'selected_stock_price_id' => $selectedStockPriceId,
                        'error_message' => $result->errors === [] ? null : implode(' ', $result->errors),
                    ]);

                    $this->incrementRunCounters($run, $status);
                } catch (Throwable $exception) {
                    $item->update([
                        'status' => 'failed',
                        'error_message' => $exception->getMessage(),
                    ]);

                    $this->incrementRunCounters($run, 'failed');
                }

                $progress->advance($this->refreshId, $holding->symbol ?? $holding->name);
            });

        if ($this->includeIndexes) {
            IndexWatchItem::query()
                ->orderBy('id')
                ->eachById(function (IndexWatchItem $item) use ($indexPriceRefresher, $progress, $run): void {
                    try {
                        $this->incrementRunCounters($run, $indexPriceRefresher->refresh($item) ? 'success' : 'unavailable');
                    } catch (Throwable) {
                        $this->incrementRunCounters($run, 'failed');
                    }

                    $progress->advance($this->refreshId, $item->symbol ?? $item->name);
                });
        }

        $progress->finish($this->refreshId);
        $run?->refresh()->update([
            'status' => $this->runStatus($run),
            'finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        app(DepotHoldingPriceRefreshProgress::class)->fail(
            $this->refreshId,
            $exception?->getMessage() ?? 'Unknown queue failure.',
        );

        StockPriceRefreshRun::query()
            ->whereKey($this->refreshId)
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_summary' => ['message' => $exception?->getMessage() ?? 'Unknown queue failure.'],
            ]);
    }

    private function itemStatus(string $status): string
    {
        return match ($status) {
            'realtime', 'fresh', 'delayed', 'closed_market' => 'success',
            'suspicious' => 'suspicious',
            'stale' => 'stale',
            'invalid' => 'invalid',
            default => 'unavailable',
        };
    }

    private function incrementRunCounters(?StockPriceRefreshRun $run, string $status): void
    {
        if (! $run) {
            return;
        }

        $column = match ($status) {
            'success' => 'success_count',
            'stale' => 'stale_count',
            'invalid' => 'invalid_count',
            'suspicious' => 'suspicious_count',
            default => 'unavailable_count',
        };

        $run->increment('processed_count');
        $run->increment($column);
    }

    private function runStatus(?StockPriceRefreshRun $run): string
    {
        if (! $run) {
            return 'finished';
        }

        if ($run->success_count === $run->total_count) {
            return 'finished';
        }

        if ($run->success_count > 0 || $run->suspicious_count > 0 || $run->stale_count > 0) {
            return 'partial';
        }

        return 'failed';
    }
}
