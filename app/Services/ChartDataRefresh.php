<?php

namespace App\Services;

use App\Jobs\LoadIndexChartPrices;
use App\Jobs\LoadStockChartIntradayData;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ChartDataRefresh
{
    public function __construct(private EodhdApiClient $apiClient, private EodhdErrorSanitizer $errorSanitizer) {}

    /** @return array{status: string, error: ?string} */
    public function request(StockHolding $holding, string $kind): array
    {
        $key = $this->key($holding, $kind);
        if (Cache::add($key, ['status' => 'queued', 'error' => null], now()->addMinutes(10))) {
            $connection = (string) config('queue.default');
            if (in_array(config("queue.connections.{$connection}.driver"), ['sync', 'deferred', 'background', 'null'], true)) {
                $connection = 'database';
            }
            try {
                LoadStockChartIntradayData::dispatch($holding->id, $kind, $key)->onConnection($connection);
            } catch (Throwable $exception) {
                $this->finish($key, $exception->getMessage());
            }
        }

        return $this->status($holding, $kind);
    }

    /** @return array{status: string, error: ?string} */
    public function status(StockHolding $holding, string $kind): array
    {
        return Cache::get($this->key($holding, $kind), ['status' => 'idle', 'error' => null]);
    }

    /** @return array{status: string, error: ?string} */
    public function requestIndex(IndexWatchItem $item, ?string $range, int $priceLimit): array
    {
        if (! $this->apiClient->configured()) {
            return ['status' => 'unavailable', 'error' => 'Index updates are unavailable.'];
        }
        $key = $this->indexKey($item, $range);
        if (in_array($this->indexStatus($item, $range)['status'], ['finished', 'failed'], true)) {
            Cache::forget($key);
        }
        if (Cache::add($key, ['status' => 'queued', 'error' => null], now()->addMinutes(10))) {
            $connection = (string) config('queue.default');
            if (in_array(config("queue.connections.{$connection}.driver"), ['sync', 'deferred', 'background', 'null'], true)) {
                $connection = 'database';
            }
            try {
                LoadIndexChartPrices::dispatch($item->id, $range, $priceLimit, $key)->onConnection($connection);
            } catch (Throwable $exception) {
                $this->finish($key, $exception->getMessage());
            }
        }

        return $this->indexStatus($item, $range);
    }

    /** @return array{status: string, error: ?string} */
    public function indexStatus(IndexWatchItem $item, ?string $range): array
    {
        return Cache::get($this->indexKey($item, $range), ['status' => 'idle', 'error' => null]);
    }

    public function running(string $key): void
    {
        Cache::put($key, ['status' => 'running', 'error' => null], now()->addMinutes(10));
    }

    public function finish(string $key, ?string $error = null): void
    {
        Cache::put($key, [
            'status' => $error === null ? 'finished' : 'failed',
            'error' => $error === null ? null : $this->errorSanitizer->message($error, 255),
        ], now()->addMinute());
    }

    private function key(StockHolding $holding, string $kind): string
    {
        return "stock-chart-intraday:{$holding->id}:{$kind}:".now()->toDateString();
    }

    private function indexKey(IndexWatchItem $item, ?string $range): string
    {
        return "index-chart-prices:{$item->id}:".($range ?? 'recent').':'.now()->toDateString();
    }
}
