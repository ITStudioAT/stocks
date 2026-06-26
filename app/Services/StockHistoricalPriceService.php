<?php

namespace App\Services;

use App\Jobs\FetchStockHistoricalPrices;
use App\Models\StockHistoricalPriceFetchItem;
use App\Models\StockHistoricalPriceFetchRun;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class StockHistoricalPriceService
{
    public function __construct(
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

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
     * @return array{date_from: string, date_to: string, required_to: string, is_complete: bool, total_count: int, available_count: int, missing_count: int, holdings: array<int, array{id: int, name: ?string, symbol: ?string, isin: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_realtime_date: ?string, latest_realtime_rows: int, latest_realtime_day_first_record_at: ?string, latest_realtime_day_last_record_at: ?string, latest_realtime_day_record_count: int, latest_realtime_table_row_count: int, previous_realtime_date: ?string, previous_realtime_day_first_record_at: ?string, previous_realtime_day_last_record_at: ?string, previous_realtime_day_record_count: int, end_of_day_first_date: ?string, end_of_day_last_date: ?string, end_of_day_row_count: int, end_of_day_table_row_count: int, is_available: bool, stored_count: int, stored_required_count: int, expected_required_count: int, first_date: ?string, latest_date: ?string}>}
     */
    public function coverage(): array
    {
        $range = $this->range();
        $tableRowCounts = [
            'stock_prices' => StockPrice::query()->count(),
            'stock_realtime_prices' => StockRealtimePrice::query()->count(),
        ];
        $holdings = StockHolding::query()
            ->orderBy('name')
            ->orderBy('symbol')
            ->get([
                'id',
                'name',
                'symbol',
                'isin',
                'exchange',
                'mic_code',
                'instrument_type',
                'country',
                'currency',
            ]);
        $coverage = $holdings
            ->map(fn (StockHolding $holding): array => $this->holdingCoverage($holding, $range['from'], $range['to'], $range['required_to'], $tableRowCounts))
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
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: ?string, finished_at: ?string, error: ?string, success_count: int, unavailable_count: int, failed_count: int, stored_count: int}
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
            'stored_count' => (int) $run->items()->sum('stored_count'),
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
     * @return array<int, array{from: Carbon, to: Carbon}>
     */
    public function missingDateRanges(StockHolding $holding, Carbon $from, Carbon $to): array
    {
        if ($from->gt($to)) {
            return [];
        }

        $storedDates = StockHoldingDailyPrice::query()
            ->where('stock_holding_id', $holding->id)
            ->whereDate('trading_date', '>=', $from->toDateString())
            ->whereDate('trading_date', '<=', $to->toDateString())
            ->pluck('trading_date')
            ->map(fn (mixed $date): string => Carbon::parse($date)->toDateString())
            ->flip();
        $ranges = [];
        $rangeStart = null;
        $previousMissingDate = null;
        $date = $from->copy();

        while ($date->lte($to)) {
            if (! $date->isWeekday()) {
                $date->addDay();

                continue;
            }

            if ($storedDates->has($date->toDateString())) {
                if ($rangeStart !== null && $previousMissingDate !== null) {
                    $ranges[] = [
                        'from' => $rangeStart,
                        'to' => $previousMissingDate,
                    ];
                }

                $rangeStart = null;
                $previousMissingDate = null;
                $date->addDay();

                continue;
            }

            $rangeStart ??= $date->copy();
            $previousMissingDate = $date->copy();
            $date->addDay();
        }

        if ($rangeStart !== null && $previousMissingDate !== null) {
            $ranges[] = [
                'from' => $rangeStart,
                'to' => $previousMissingDate,
            ];
        }

        return $ranges;
    }

    public function requiredToFor(Carbon $dateTo): Carbon
    {
        return $this->previousWeekday($dateTo->copy()->subDay());
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
     * @param  array{stock_prices: int, stock_realtime_prices: int}  $tableRowCounts
     * @return array{id: int, name: ?string, symbol: ?string, isin: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_realtime_date: ?string, latest_realtime_rows: int, latest_realtime_day_first_record_at: ?string, latest_realtime_day_last_record_at: ?string, latest_realtime_day_record_count: int, latest_realtime_table_row_count: int, previous_realtime_date: ?string, previous_realtime_day_first_record_at: ?string, previous_realtime_day_last_record_at: ?string, previous_realtime_day_record_count: int, end_of_day_first_date: ?string, end_of_day_last_date: ?string, end_of_day_row_count: int, end_of_day_table_row_count: int, is_available: bool, stored_count: int, stored_required_count: int, expected_required_count: int, first_date: ?string, latest_date: ?string}
     */
    private function holdingCoverage(StockHolding $holding, Carbon $from, Carbon $to, Carbon $requiredTo, array $tableRowCounts): array
    {
        $prices = StockHoldingDailyPrice::query()
            ->where('stock_holding_id', $holding->id)
            ->whereBetween('trading_date', [$from->toDateString(), $to->toDateString()]);
        $storedCount = (clone $prices)->count();
        $firstDate = (clone $prices)->min('trading_date');
        $latestDate = (clone $prices)->max('trading_date');
        $missingDateRanges = $this->missingDateRanges($holding, $from, $requiredTo);
        $expectedRequiredDateCount = $this->weekdayCount($from, $requiredTo);
        $storedRequiredDateCount = $expectedRequiredDateCount;
        $storedRequiredDateCount -= collect($missingDateRanges)->sum(
            fn (array $range): int => $this->weekdayCount($range['from'], $range['to']),
        );
        $hasStoredDateCoverage = $firstDate !== null
            && $latestDate !== null
            && Carbon::parse($firstDate)->toDateString() <= $from->toDateString()
            && Carbon::parse($latestDate)->toDateString() >= $requiredTo->toDateString()
            && $missingDateRanges === [];
        $latestRealtimeDate = $this->latestRealtimeTimestamp($holding);
        $latestRealtimeDateForPayload = $latestRealtimeDate === null
            ? null
            : Carbon::parse($latestRealtimeDate, 'UTC')->setTimezone('Europe/Vienna')->toIso8601String();
        $latestRealtimeDaySummary = $this->latestRealtimeDaySummary($holding, $latestRealtimeDate);
        $previousRealtimeDate = $this->previousRealtimeDayTimestamp($holding, $latestRealtimeDate);
        $previousRealtimeDateForPayload = $previousRealtimeDate === null
            ? null
            : Carbon::parse($previousRealtimeDate, 'UTC')->setTimezone('Europe/Vienna')->toIso8601String();
        $previousRealtimeDaySummary = $this->latestRealtimeDaySummary($holding, $previousRealtimeDate);
        $endOfDaySummary = $this->endOfDayStockPriceSummary($holding);

        return [
            'id' => $holding->id,
            'name' => $holding->name,
            'symbol' => $holding->symbol,
            'isin' => $holding->isin,
            'exchange' => $holding->exchange,
            'mic_code' => $holding->mic_code,
            'instrument_type' => $holding->instrument_type,
            'country' => $holding->country,
            'currency' => $holding->currency,
            'latest_realtime_date' => $latestRealtimeDateForPayload,
            'latest_realtime_rows' => $this->latestRealtimeRows($holding),
            'latest_realtime_day_first_record_at' => $latestRealtimeDaySummary['first_record_at'],
            'latest_realtime_day_last_record_at' => $latestRealtimeDaySummary['last_record_at'],
            'latest_realtime_day_record_count' => $latestRealtimeDaySummary['record_count'],
            'latest_realtime_table_row_count' => $tableRowCounts['stock_realtime_prices'],
            'previous_realtime_date' => $previousRealtimeDateForPayload,
            'previous_realtime_day_first_record_at' => $previousRealtimeDaySummary['first_record_at'],
            'previous_realtime_day_last_record_at' => $previousRealtimeDaySummary['last_record_at'],
            'previous_realtime_day_record_count' => $previousRealtimeDaySummary['record_count'],
            'end_of_day_first_date' => $endOfDaySummary['first_date'],
            'end_of_day_last_date' => $endOfDaySummary['last_date'],
            'end_of_day_row_count' => $endOfDaySummary['row_count'],
            'end_of_day_table_row_count' => $tableRowCounts['stock_prices'],
            'is_available' => $hasStoredDateCoverage,
            'stored_count' => $storedCount,
            'stored_required_count' => $storedRequiredDateCount,
            'expected_required_count' => $expectedRequiredDateCount,
            'first_date' => $firstDate ? Carbon::parse($firstDate)->toDateString() : null,
            'latest_date' => $latestDate ? Carbon::parse($latestDate)->toDateString() : null,
        ];
    }

    /**
     * @return array{first_date: ?string, last_date: ?string, row_count: int}
     */
    private function endOfDayStockPriceSummary(StockHolding $holding): array
    {
        $tradingDates = $this->stockPriceCatalog->pricesForHolding($holding)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->orderBy('as_of')
            ->pluck('as_of')
            ->map(fn (mixed $asOf): string => Carbon::parse($asOf, 'UTC')->setTimezone('Europe/Vienna')->toDateString())
            ->unique()
            ->values();

        return [
            'first_date' => $tradingDates->first(),
            'last_date' => $tradingDates->last(),
            'row_count' => $tradingDates->count(),
        ];
    }

    /**
     * @return array{first_record_at: ?string, last_record_at: ?string, record_count: int}
     */
    private function latestRealtimeDaySummary(StockHolding $holding, ?string $latestRealtimeDate): array
    {
        if ($latestRealtimeDate === null) {
            return [
                'first_record_at' => null,
                'last_record_at' => null,
                'record_count' => 0,
            ];
        }

        $localDate = Carbon::parse($latestRealtimeDate, 'UTC')->setTimezone('Europe/Vienna');
        $dayStart = $localDate->copy()->startOfDay()->utc();
        $dayEnd = $localDate->copy()->addDay()->startOfDay()->utc();

        $summary = StockRealtimePrice::query()
            ->where('stock_holding_id', $holding->id)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $dayStart)
            ->where('as_of', '<', $dayEnd)
            ->selectRaw('MIN(as_of) as first_record_at, MAX(as_of) as last_record_at, COUNT(*) as record_count')
            ->first();

        return [
            'first_record_at' => $summary && $summary->first_record_at !== null
                ? Carbon::parse($summary->first_record_at, 'UTC')->setTimezone('Europe/Vienna')->toIso8601String()
                : null,
            'last_record_at' => $summary && $summary->last_record_at !== null
                ? Carbon::parse($summary->last_record_at, 'UTC')->setTimezone('Europe/Vienna')->toIso8601String()
                : null,
            'record_count' => (int) ($summary->record_count ?? 0),
        ];
    }


    private function latestRealtimeTimestamp(StockHolding $holding): ?string
    {
        return StockRealtimePrice::query()
            ->where('stock_holding_id', $holding->id)
            ->orderByDesc('as_of')
            ->value('as_of');
    }

    private function previousRealtimeDayTimestamp(StockHolding $holding, ?string $latestRealtimeDate): ?string
    {
        if ($latestRealtimeDate === null) {
            return null;
        }

        $latestLocalDayStart = Carbon::parse($latestRealtimeDate, 'UTC')
            ->setTimezone('Europe/Vienna')
            ->startOfDay()
            ->utc();

        return StockRealtimePrice::query()
            ->where('stock_holding_id', $holding->id)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '<', $latestLocalDayStart)
            ->orderByDesc('as_of')
            ->value('as_of');
    }

    private function latestRealtimeRows(StockHolding $holding): int
    {
        $latestDate = $this->latestRealtimeTimestamp($holding);

        if ($latestDate === null) {
            return 0;
        }

        return StockRealtimePrice::query()
            ->where('stock_holding_id', $holding->id)
            ->where('as_of', $latestDate)
            ->count();
    }

    private function previousWeekday(Carbon $date): Carbon
    {
        while ($date->isWeekend()) {
            $date->subDay();
        }

        return $date;
    }

    private function weekdayCount(Carbon $from, Carbon $to): int
    {
        $count = 0;
        $date = $from->copy();

        while ($date->lte($to)) {
            if ($date->isWeekday()) {
                $count++;
            }

            $date->addDay();
        }

        return $count;
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
