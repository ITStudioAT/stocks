<?php

namespace App\Services;

use App\Jobs\FetchStockHistoricalPrices;
use App\Models\StockHistoricalPriceFetchItem;
use App\Models\StockHistoricalPriceFetchRun;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class StockHistoricalPriceService
{
    /**
     * @return array{message: string, coverage: array<string, mixed>, refresh: array<string, mixed>|null}
     */
    public function ensure(): array
    {
        $coverage = $this->coverage();

        if ($coverage['is_complete']) {
            return [
                'message' => 'Historical stock prices are available.',
                'coverage' => $coverage,
                'refresh' => null,
            ];
        }

        $runningRun = $this->runningRun();

        if ($runningRun !== null) {
            return [
                'message' => 'Historical stock prices are being fetched.',
                'coverage' => $coverage,
                'refresh' => $this->refreshPayload($runningRun),
            ];
        }

        $missingHoldingIds = collect($coverage['holdings'])
            ->filter(fn (array $holding): bool => ! $holding['is_available'])
            ->pluck('id')
            ->all();

        if ($missingHoldingIds === []) {
            return [
                'message' => 'Historical stock prices are available.',
                'coverage' => $coverage,
                'refresh' => null,
            ];
        }

        $run = $this->createRun($missingHoldingIds, $coverage['date_from'], $coverage['date_to']);

        FetchStockHistoricalPrices::dispatch($run->id);

        return [
            'message' => trans_choice('{1} 1 stock queued for historical price fetching.|[2,*] :count stocks queued for historical price fetching.', count($missingHoldingIds)),
            'coverage' => $coverage,
            'refresh' => $this->refreshPayload($run),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(string $refreshId): ?array
    {
        $run = StockHistoricalPriceFetchRun::query()->find($refreshId);

        if (! $run) {
            return null;
        }

        return [
            'message' => $this->message($run),
            'coverage' => $this->coverage(),
            'refresh' => $this->refreshPayload($run),
        ];
    }

    /**
     * @return array{date_from: string, date_to: string, required_to: string, is_complete: bool, total_count: int, available_count: int, missing_count: int, holdings: array<int, array{id: int, name: ?string, symbol: ?string, is_available: bool, stored_count: int, first_date: ?string, latest_date: ?string}>}
     */
    public function coverage(): array
    {
        $range = $this->range();
        $holdings = StockHolding::query()
            ->orderBy('name')
            ->orderBy('symbol')
            ->get(['id', 'name', 'symbol']);
        $coverage = $holdings
            ->map(fn (StockHolding $holding): array => $this->holdingCoverage($holding, $range['from'], $range['to'], $range['required_to']))
            ->values();
        $availableCount = $coverage->where('is_available', true)->count();

        return [
            'date_from' => $range['from']->toDateString(),
            'date_to' => $range['to']->toDateString(),
            'required_to' => $range['required_to']->toDateString(),
            'is_complete' => $holdings->count() === $availableCount,
            'total_count' => $holdings->count(),
            'available_count' => $availableCount,
            'missing_count' => $holdings->count() - $availableCount,
            'holdings' => $coverage->all(),
        ];
    }

    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: ?string, finished_at: ?string, error: ?string, success_count: int, unavailable_count: int, failed_count: int}
     */
    public function refreshPayload(StockHistoricalPriceFetchRun $run): array
    {
        return [
            'refresh_id' => $run->id,
            'status' => $run->status,
            'processed' => $run->processed_count,
            'total' => $run->total_count,
            'step' => "{$run->processed_count}/{$run->total_count}",
            'message' => $this->message($run),
            'current' => $run->current,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'error' => is_array($run->error_summary) ? ($run->error_summary['message'] ?? null) : null,
            'success_count' => $run->success_count,
            'unavailable_count' => $run->unavailable_count,
            'failed_count' => $run->failed_count,
        ];
    }

    public function runningRun(): ?StockHistoricalPriceFetchRun
    {
        return StockHistoricalPriceFetchRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->latest()
            ->first();
    }

    /**
     * @return array{from: Carbon, to: Carbon, required_to: Carbon}
     */
    public function range(): array
    {
        $to = now()->startOfDay();

        return [
            'from' => $to->copy()->subYear(),
            'to' => $to,
            'required_to' => $this->previousWeekday($to->copy()->subDay()),
        ];
    }

    /**
     * @param  array<int, int>  $stockHoldingIds
     */
    private function createRun(array $stockHoldingIds, string $dateFrom, string $dateTo): StockHistoricalPriceFetchRun
    {
        $run = StockHistoricalPriceFetchRun::query()->create([
            'id' => 'history-'.Str::uuid()->toString(),
            'status' => 'queued',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total_count' => count($stockHoldingIds),
        ]);

        foreach ($stockHoldingIds as $stockHoldingId) {
            StockHistoricalPriceFetchItem::query()->create([
                'fetch_run_id' => $run->id,
                'stock_holding_id' => $stockHoldingId,
                'status' => 'queued',
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);
        }

        return $run;
    }

    /**
     * @return array{id: int, name: ?string, symbol: ?string, is_available: bool, stored_count: int, first_date: ?string, latest_date: ?string}
     */
    private function holdingCoverage(StockHolding $holding, Carbon $from, Carbon $to, Carbon $requiredTo): array
    {
        $prices = StockHoldingDailyPrice::query()
            ->where('stock_holding_id', $holding->id)
            ->whereBetween('trading_date', [$from->toDateString(), $to->toDateString()]);
        $storedCount = (clone $prices)->count();
        $firstDate = (clone $prices)->min('trading_date');
        $latestDate = (clone $prices)->max('trading_date');
        $hasSuccessfulRangeFetch = StockHistoricalPriceFetchItem::query()
            ->where('stock_holding_id', $holding->id)
            ->where('status', 'success')
            ->where('date_from', '<=', $from->toDateString())
            ->where('date_to', '>=', $to->toDateString())
            ->exists();
        $hasStoredDateCoverage = $firstDate !== null
            && $latestDate !== null
            && Carbon::parse($firstDate)->lte($from)
            && Carbon::parse($latestDate)->gte($requiredTo);

        return [
            'id' => $holding->id,
            'name' => $holding->name,
            'symbol' => $holding->symbol,
            'is_available' => $hasSuccessfulRangeFetch || $hasStoredDateCoverage,
            'stored_count' => $storedCount,
            'first_date' => $firstDate ? Carbon::parse($firstDate)->toDateString() : null,
            'latest_date' => $latestDate ? Carbon::parse($latestDate)->toDateString() : null,
        ];
    }

    private function previousWeekday(Carbon $date): Carbon
    {
        while ($date->isWeekend()) {
            $date->subDay();
        }

        return $date;
    }

    private function message(StockHistoricalPriceFetchRun $run): string
    {
        if ($run->status === 'queued') {
            return trans_choice('{1} 1 stock queued for historical price fetching.|[2,*] :count stocks queued for historical price fetching.', $run->total_count);
        }

        if ($run->status === 'running') {
            return "Fetching historical stock prices ({$run->processed_count}/{$run->total_count})...";
        }

        if ($run->status === 'failed') {
            return 'Historical stock price fetching failed.';
        }

        if ($run->status === 'partial') {
            return 'Historical stock price fetching finished with missing data.';
        }

        return 'Historical stock prices fetched.';
    }
}
