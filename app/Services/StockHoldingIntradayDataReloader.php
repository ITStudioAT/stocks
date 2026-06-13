<?php

namespace App\Services;

use App\Models\EodhdExchange;
use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockHoldingIntradayPrice;
use App\Models\StockHoldingIntradayReloadRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StockHoldingIntradayDataReloader
{
    private const BackfillDayCount = 365;

    private const DayCount = 3;

    private const Interval = '5m';

    private const SourceKey = 'eodhd_intraday';

    public function __construct(
        private EodhdApiClient $eodhdApiClient,
        private EodhdMarketData $marketData,
    ) {}

    /**
     * @return array<int, array{id: int, symbol: ?string, name: string}>
     */
    public function stockOptions(): array
    {
        return StockHolding::query()
            ->get(['id', 'name', 'symbol'])
            ->map(fn (StockHolding $holding): array => [
                'id' => $holding->id,
                'symbol' => $holding->symbol,
                'name' => $holding->name ?: ($holding->symbol ?: "Stock {$holding->id}"),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function selectedHolding(mixed $stockId = null): ?StockHolding
    {
        $query = StockHolding::query()->orderBy('name')->orderBy('symbol');

        if (is_numeric($stockId)) {
            return (clone $query)->find((int) $stockId);
        }

        return $query->first();
    }

    /**
     * @return array<int, array{title: string, trading_date: string, interval: string, overview: ?string, rows: array<int, array<string, mixed>>}>
     */
    public function candlePayloads(StockHolding $holding): array
    {
        $days = collect($this->lastOverviewDays($holding));
        $tradeDates = $days
            ->map(fn (array $day): string => $day['date']->toDateString())
            ->values();

        foreach ($tradeDates as $tradingDate) {
            $this->deleteEmptyPriceRows($holding, Carbon::parse($tradingDate));
        }

        $this->adoptStoredIntradayPricesForMissingCandleDays($holding, $days);

        $candlesByDate = StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->where('interval', self::Interval)
            ->where('source_key', self::SourceKey)
            ->where(function ($query) use ($tradeDates): void {
                foreach ($tradeDates as $tradingDate) {
                    $query->orWhereDate('trading_date', $tradingDate);
                }
            })
            ->where(function ($query): void {
                $query
                    ->whereNotNull('open')
                    ->orWhereNotNull('high')
                    ->orWhereNotNull('low')
                    ->orWhereNotNull('close');
            })
            ->orderBy('as_of')
            ->get(['trading_date', 'interval', 'timestamp', 'gmtoffset', 'datetime', 'open', 'high', 'low', 'close', 'volume'])
            ->groupBy(fn (StockHoldingIntradayCandle $candle): string => $candle->trading_date?->toDateString() ?? '');

        return $days
            ->map(fn (array $day): array => [
                'title' => 'Intraday '.$this->displayDate($day['date']->toDateString()).' - '.self::Interval,
                'trading_date' => $day['date']->toDateString(),
                'interval' => self::Interval,
                'overview' => $day['overview'],
                'rows' => ($candlesByDate->get($day['date']->toDateString()) ?? collect())
                    ->map(fn (StockHoldingIntradayCandle $candle): array => $this->candlePayload($candle))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array{date: Carbon, is_trading_day: bool, overview: ?string}>  $days
     */
    private function adoptStoredIntradayPricesForMissingCandleDays(StockHolding $holding, Collection $days): void
    {
        $days
            ->filter(fn (array $day): bool => $day['is_trading_day'])
            ->each(function (array $day) use ($holding): void {
                $tradingDate = $day['date']->toDateString();

                $now = now();
                $rows = [];

                if (! $this->hasStoredCandlePrices($holding, $tradingDate)) {
                    $rows = $this->missingCandleRows($holding, $this->intradayPriceCandleRows($holding, $tradingDate, $now));
                }

                if ($rows === []) {
                    return;
                }

                StockHoldingIntradayCandle::query()->upsert(
                    $rows,
                    uniqueBy: ['stock_holding_id', 'interval', 'as_of'],
                    update: ['trading_date', 'timestamp', 'gmtoffset', 'datetime', 'close', 'currency', 'source_key', 'source_name', 'source_url', 'raw_payload', 'updated_at'],
                );
            });
    }

    private function hasStoredCandlePrices(StockHolding $holding, string $tradingDate): bool
    {
        return StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->where('interval', self::Interval)
            ->where('source_key', self::SourceKey)
            ->whereDate('trading_date', $tradingDate)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('open')
                    ->orWhereNotNull('high')
                    ->orWhereNotNull('low')
                    ->orWhereNotNull('close');
            })
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function missingCandleRows(StockHolding $holding, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $existingAsOf = StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->where('interval', self::Interval)
            ->where('source_key', self::SourceKey)
            ->whereIn('as_of', collect($rows)->map(fn (array $row): Carbon => $row['as_of'])->all())
            ->pluck('as_of')
            ->map(fn (mixed $asOf): string => Carbon::parse($asOf)->utc()->toDateTimeString())
            ->flip();

        return collect($rows)
            ->reject(fn (array $row): bool => $existingAsOf->has($row['as_of']->copy()->utc()->toDateTimeString()))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function intradayPriceCandleRows(StockHolding $holding, string $tradingDate, Carbon $now): array
    {
        return $this->uniqueCandleRows(
            StockHoldingIntradayPrice::query()
                ->where('stock_holding_id', $holding->id)
                ->whereDate('trading_date', $tradingDate)
                ->whereNotNull('price')
                ->whereNotNull('as_of')
                ->orderBy('sample_index')
                ->orderBy('as_of')
                ->orderBy('id')
                ->get(['id', 'price', 'currency', 'as_of', 'source_name', 'price_type'])
                ->map(fn (StockHoldingIntradayPrice $intradayPrice): ?array => $this->intradayPriceCandleRow($holding, $tradingDate, $intradayPrice, $now))
                ->filter()
                ->values(),
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function uniqueCandleRows(Collection $rows): array
    {
        return $rows
            ->reverse()
            ->unique(fn (array $row): string => $row['as_of']->toDateTimeString())
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function intradayPriceCandleRow(
        StockHolding $holding,
        string $tradingDate,
        StockHoldingIntradayPrice $intradayPrice,
        Carbon $now,
    ): ?array {
        if ($intradayPrice->price === null) {
            return null;
        }

        $asOf = $this->intradayPriceAsOf($intradayPrice);

        if ($asOf === null) {
            return null;
        }

        $price = (string) $intradayPrice->price;

        return [
            'stock_holding_id' => $holding->id,
            'trading_date' => $tradingDate,
            'interval' => self::Interval,
            'as_of' => $asOf,
            'timestamp' => $asOf->timestamp,
            'gmtoffset' => 0,
            'datetime' => $asOf->format('Y-m-d H:i:s'),
            'open' => null,
            'high' => null,
            'low' => null,
            'close' => $price,
            'volume' => null,
            'currency' => $intradayPrice->currency ?? $holding->currency,
            'source_key' => self::SourceKey,
            'source_name' => $intradayPrice->source_name ?? 'Stored intraday price',
            'source_url' => null,
            'raw_payload' => json_encode([
                'source_intraday_price_id' => $intradayPrice->id,
                'price' => $price,
                'price_type' => $intradayPrice->price_type,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function intradayPriceAsOf(StockHoldingIntradayPrice $intradayPrice): ?Carbon
    {
        $value = $intradayPrice->getRawOriginal('as_of');

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')->utc();
    }

    public function latestRefreshPayload(?StockHolding $holding = null): ?array
    {
        $run = StockHoldingIntradayReloadRun::query()
            ->when($holding, fn ($query) => $query->where('stock_holding_id', $holding->id))
            ->when(! $holding, fn ($query) => $query->whereNull('stock_holding_id'))
            ->latest('finished_at')
            ->latest()
            ->first();

        return $run ? $this->refreshPayload($run) : null;
    }

    public function createRun(): StockHoldingIntradayReloadRun
    {
        $date = now('Europe/Vienna')->startOfDay();

        return StockHoldingIntradayReloadRun::query()->create([
            'id' => 'intraday-all-'.Str::uuid()->toString(),
            'stock_holding_id' => null,
            'status' => 'queued',
            'total_count' => StockHolding::query()->count(),
            'date_from' => $date->copy()->subDays(self::DayCount * 2)->toDateString(),
            'date_to' => $date->toDateString(),
            'started_at' => now(),
            'message' => 'Intraday reload queued.',
        ]);
    }

    public function createMissingYearRun(): StockHoldingIntradayReloadRun
    {
        $date = now('Europe/Vienna')->startOfDay();

        return StockHoldingIntradayReloadRun::query()->create([
            'id' => 'intraday-missing-'.Str::uuid()->toString(),
            'stock_holding_id' => null,
            'status' => 'queued',
            'total_count' => StockHolding::query()->count(),
            'date_from' => $date->copy()->subDays(self::BackfillDayCount - 1)->toDateString(),
            'date_to' => $date->toDateString(),
            'started_at' => now(),
            'message' => 'Missing intraday backfill queued.',
        ]);
    }

    public function runningRun(): ?StockHoldingIntradayReloadRun
    {
        return StockHoldingIntradayReloadRun::query()
            ->whereNull('stock_holding_id')
            ->whereIn('status', ['queued', 'running'])
            ->latest()
            ->first();
    }

    public function reload(StockHolding $holding): StockHoldingIntradayReloadRun
    {
        $days = $this->lastOverviewDays($holding);

        if ($days === []) {
            throw new RuntimeException('No intraday days could be resolved for this stock.');
        }

        $tradeDays = collect($days)
            ->filter(fn (array $day): bool => $day['is_trading_day'])
            ->values()
            ->all();

        $run = StockHoldingIntradayReloadRun::query()->create([
            'id' => 'intraday-'.Str::uuid()->toString(),
            'stock_holding_id' => $holding->id,
            'status' => 'running',
            'date_from' => $days[array_key_last($days)]['date']->toDateString(),
            'date_to' => $days[0]['date']->toDateString(),
            'started_at' => now(),
        ]);

        try {
            $storedCount = 0;

            foreach ($tradeDays as $day) {
                $records = $this->fetchIntradayRecords($holding, $day['date']);
                $storedCount += $this->storeRecords($holding, $day['date'], $records);
            }

            $run->update([
                'status' => 'finished',
                'stored_count' => $storedCount,
                'message' => "{$storedCount} intraday candles loaded/updated.",
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'message' => 'Intraday reload failed.',
                'error_summary' => ['message' => Str::limit($exception->getMessage(), 255, '')],
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        return $run->refresh();
    }

    public function import(string $runId): void
    {
        $run = StockHoldingIntradayReloadRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'total_count' => StockHolding::query()->count(),
            'message' => 'Reloading intraday data...',
        ]);

        StockHolding::query()
            ->orderBy('id')
            ->chunkById(50, function ($holdings) use ($run): void {
                foreach ($holdings as $holding) {
                    $run->update([
                        'current' => $holding->name ?: $holding->symbol,
                    ]);

                    try {
                        $holdingRun = $this->reload($holding);
                        $run->increment('stored_count', $holdingRun->stored_count);
                        $run->increment('success_count');
                    } catch (Throwable $exception) {
                        $run->increment('failed_count');
                        $run->update([
                            'error_summary' => ['message' => Str::limit($exception->getMessage(), 255, '')],
                        ]);
                    } finally {
                        $run->increment('processed_count');
                    }
                }
            });

        $run->refresh();
        $run->update([
            'status' => $run->failed_count > 0 ? ($run->success_count > 0 ? 'partial' : 'failed') : 'finished',
            'current' => null,
            'message' => $this->reloadAllMessage([
                'total' => $run->total_count,
                'success_count' => $run->success_count,
                'failed_count' => $run->failed_count,
                'stored_count' => $run->stored_count,
                'message' => '',
            ]),
            'finished_at' => now(),
        ]);
    }

    public function importMissingYear(string $runId): void
    {
        $run = StockHoldingIntradayReloadRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $dateTo = $run->date_to ?? now('Europe/Vienna')->startOfDay();
        $dateFrom = $run->date_from ?? $dateTo->copy()->subDays(self::BackfillDayCount - 1);

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'total_count' => StockHolding::query()->count(),
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'message' => 'Backfilling missing intraday candles...',
        ]);

        StockHolding::query()
            ->orderBy('id')
            ->chunkById(50, function ($holdings) use ($dateFrom, $dateTo, $run): void {
                foreach ($holdings as $holding) {
                    $run->update([
                        'current' => $holding->name ?: $holding->symbol,
                    ]);

                    try {
                        $storedCount = $this->backfillMissingForHolding($holding, $dateFrom, $dateTo, $run);
                        $run->increment('stored_count', $storedCount);
                        $run->increment('success_count');
                    } catch (Throwable $exception) {
                        $run->increment('failed_count');
                        $run->update([
                            'error_summary' => ['message' => Str::limit($exception->getMessage(), 255, '')],
                        ]);
                    } finally {
                        $run->increment('processed_count');
                    }
                }
            });

        $run->refresh();
        $run->update([
            'status' => $run->failed_count > 0 ? ($run->success_count > 0 ? 'partial' : 'failed') : 'finished',
            'current' => null,
            'message' => $this->reloadAllMessage([
                'total' => $run->total_count,
                'success_count' => $run->success_count,
                'failed_count' => $run->failed_count,
                'stored_count' => $run->stored_count,
                'message' => '',
            ]),
            'finished_at' => now(),
        ]);
    }

    public function fail(string $runId, string $message): void
    {
        $run = StockHoldingIntradayReloadRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $run->update([
            'status' => 'failed',
            'current' => null,
            'message' => 'Intraday reload failed.',
            'error_summary' => ['message' => Str::limit($message, 255, '')],
            'finished_at' => now(),
        ]);
    }

    /**
     * @return array{total: int, success_count: int, failed_count: int, stored_count: int, message: string}
     */
    public function reloadAll(): array
    {
        $summary = [
            'total' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'stored_count' => 0,
            'message' => '',
        ];

        StockHolding::query()
            ->orderBy('id')
            ->chunkById(50, function ($holdings) use (&$summary): void {
                foreach ($holdings as $holding) {
                    $summary['total']++;

                    try {
                        $run = $this->reload($holding);
                        $summary['stored_count'] += $run->stored_count;

                        if ($run->status === 'failed') {
                            $summary['failed_count']++;

                            continue;
                        }

                        $summary['success_count']++;
                    } catch (Throwable) {
                        $summary['failed_count']++;
                    }
                }
            });

        $summary['message'] = $this->reloadAllMessage($summary);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshPayload(StockHoldingIntradayReloadRun $run): array
    {
        $processed = $run->stock_holding_id === null
            ? $run->processed_count
            : ($run->status === 'running' ? 0 : 1);
        $total = $run->stock_holding_id === null
            ? $run->total_count
            : 1;

        return [
            'refresh_id' => $run->id,
            'status' => $run->status,
            'processed' => $processed,
            'total' => $total,
            'step' => "{$processed}/{$total}",
            'message' => $run->message ?? 'Intraday reload finished.',
            'current' => $run->current ?? $run->stockHolding?->name ?? $run->stockHolding?->symbol,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'date_from' => $run->date_from?->toDateString(),
            'date_to' => $run->date_to?->toDateString(),
            'stored_count' => $run->stored_count,
            'success_count' => $run->success_count,
            'failed_count' => $run->failed_count,
            'error' => is_array($run->error_summary) ? ($run->error_summary['message'] ?? null) : null,
        ];
    }

    /**
     * @param  array{total: int, success_count: int, failed_count: int, stored_count: int, message: string}  $summary
     */
    private function reloadAllMessage(array $summary): string
    {
        if ($summary['total'] === 0) {
            return 'No stocks found for intraday reload.';
        }

        if ($summary['failed_count'] > 0) {
            return "{$summary['stored_count']} intraday candles loaded/updated for {$summary['success_count']}/{$summary['total']} stocks. {$summary['failed_count']} stocks failed.";
        }

        return "{$summary['stored_count']} intraday candles loaded/updated for {$summary['total']} stocks.";
    }

    private function backfillMissingForHolding(
        StockHolding $holding,
        Carbon $dateFrom,
        Carbon $dateTo,
        StockHoldingIntradayReloadRun $run,
    ): int {
        $storedCount = 0;
        $exchange = $this->exchangeForHolding($holding);
        $timezone = $exchange?->timezone ?: 'Europe/Vienna';
        $tradingDays = $this->tradingDaysInRange($holding, $dateFrom, $dateTo);
        $existingDates = $this->storedEodhdCandleDates($holding, $dateFrom, $dateTo);
        $holdingName = $holding->name ?: ($holding->symbol ?: "Stock {$holding->id}");

        foreach ($this->missingTradingDayRanges($tradingDays, $existingDates) as $range) {
            $missingDateKeys = collect($range['days'])
                ->map(fn (Carbon $day): string => $day->toDateString())
                ->flip()
                ->all();

            foreach ($range['days'] as $day) {
                $this->deleteEmptyPriceRows($holding, $day);
            }

            $run->update([
                'current' => "Fetching data for {$holdingName}",
                'message' => "Fetching data for {$holdingName}: {$range['from']->toDateString()} to {$range['to']->toDateString()}...",
            ]);

            $records = $this->fetchIntradayRecords($holding, $range['from'], $range['to']);

            $run->update([
                'current' => "Storing data for {$holdingName}",
                'message' => "Storing data for {$holdingName}: preparing ".count($records).' rows...',
            ]);

            $storedCount += $this->storeRangeRecords($holding, $range['from'], $range['to'], $records, $timezone, $missingDateKeys, $run, $holdingName);
        }

        return $storedCount;
    }

    /**
     * @return array<int, Carbon>
     */
    private function tradingDaysInRange(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): array
    {
        $exchange = $this->exchangeForHolding($holding);
        $timezone = $exchange?->timezone ?: 'Europe/Vienna';
        $holidayLabels = $this->holidayLabels($exchange);
        $workingDays = $this->workingDays($exchange);
        $date = $dateFrom->copy()->setTimezone($timezone)->startOfDay();
        $lastDate = $dateTo->copy()->setTimezone($timezone)->startOfDay();
        $days = [];

        while ($date->lessThanOrEqualTo($lastDate)) {
            $dayName = Str::lower($date->format('D'));
            $dateKey = $date->toDateString();

            if (! array_key_exists($dateKey, $holidayLabels) && in_array($dayName, $workingDays, true)) {
                $days[] = $date->copy();
            }

            $date->addDay();
        }

        return $days;
    }

    /**
     * @return array<string, true>
     */
    private function storedEodhdCandleDates(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): array
    {
        return StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->where('interval', self::Interval)
            ->where('source_key', self::SourceKey)
            ->whereBetween('trading_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where(function ($query): void {
                $query
                    ->whereNotNull('open')
                    ->orWhereNotNull('high')
                    ->orWhereNotNull('low')
                    ->orWhereNotNull('close');
            })
            ->pluck('trading_date')
            ->map(fn (mixed $tradingDate): string => Carbon::parse((string) $tradingDate)->toDateString())
            ->flip()
            ->map(fn (): bool => true)
            ->all();
    }

    /**
     * @param  array<int, Carbon>  $tradingDays
     * @param  array<string, true>  $existingDates
     * @return array<int, array{from: Carbon, to: Carbon, days: array<int, Carbon>}>
     */
    private function missingTradingDayRanges(array $tradingDays, array $existingDates): array
    {
        $ranges = [];
        $currentDays = [];

        foreach ($tradingDays as $day) {
            if (array_key_exists($day->toDateString(), $existingDates)) {
                if ($currentDays !== []) {
                    $ranges[] = $this->tradingDayRange($currentDays);
                    $currentDays = [];
                }

                continue;
            }

            $currentDays[] = $day;
        }

        if ($currentDays !== []) {
            $ranges[] = $this->tradingDayRange($currentDays);
        }

        return $ranges;
    }

    /**
     * @param  array<int, Carbon>  $days
     * @return array{from: Carbon, to: Carbon, days: array<int, Carbon>}
     */
    private function tradingDayRange(array $days): array
    {
        return [
            'from' => $days[0],
            'to' => $days[array_key_last($days)],
            'days' => $days,
        ];
    }

    /**
     * @return array<int, array{date: Carbon, is_trading_day: bool, overview: ?string}>
     */
    private function lastOverviewDays(StockHolding $holding): array
    {
        $exchange = $this->exchangeForHolding($holding);
        $timezone = $exchange?->timezone ?: 'Europe/Vienna';
        $holidayLabels = $this->holidayLabels($exchange);
        $workingDays = $this->workingDays($exchange);
        $date = now($timezone)->startOfDay();
        $days = [];

        while (count($days) < self::DayCount) {
            $dayName = Str::lower($date->format('D'));
            $dateKey = $date->toDateString();
            $holidayLabel = $holidayLabels[$dateKey] ?? null;

            if ($holidayLabel !== null) {
                $days[] = [
                    'date' => $date->copy(),
                    'is_trading_day' => false,
                    'overview' => "Market closed: {$holidayLabel}",
                ];
            } elseif (in_array($dayName, $workingDays, true)) {
                $days[] = [
                    'date' => $date->copy(),
                    'is_trading_day' => true,
                    'overview' => null,
                ];
            }

            $date->subDay();
        }

        return $days;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchIntradayRecords(StockHolding $holding, Carbon $fromDay, ?Carbon $toDay = null): array
    {
        $toDay ??= $fromDay;
        $from = $fromDay->copy()->startOfDay()->utc();
        $to = $toDay->copy()->endOfDay()->utc();
        $response = $this->eodhdApiClient->get('intraday/'.$this->eodhdSymbol($holding), [
            'fmt' => 'json',
            'interval' => self::Interval,
            'from' => $from->timestamp,
            'to' => $to->timestamp,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("EODHD intraday request failed with HTTP {$response->status()}.");
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('EODHD returned an invalid intraday response.');
        }

        if (($payload['status'] ?? null) === 'error') {
            $message = is_string($payload['message'] ?? null) ? $payload['message'] : 'EODHD returned an error response.';

            throw new RuntimeException($message);
        }

        return collect($payload)
            ->filter(fn (mixed $record): bool => is_array($record))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function storeRecords(StockHolding $holding, Carbon $tradingDate, array $records): int
    {
        $now = now();
        $sourceUrl = $this->sourceUrl($holding, $tradingDate, $tradingDate);
        $this->deleteEmptyPriceRows($holding, $tradingDate);

        $rows = collect($records)
            ->map(fn (array $record): ?array => $this->row($holding, $tradingDate, $record, $sourceUrl, $now))
            ->filter()
            ->values()
            ->all();

        if ($rows === []) {
            return 0;
        }

        StockHoldingIntradayCandle::query()->upsert(
            $rows,
            uniqueBy: ['stock_holding_id', 'interval', 'as_of'],
            update: ['trading_date', 'timestamp', 'gmtoffset', 'datetime', 'open', 'high', 'low', 'close', 'volume', 'currency', 'source_key', 'source_name', 'source_url', 'raw_payload', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<string, true>  $missingDateKeys
     */
    private function storeRangeRecords(
        StockHolding $holding,
        Carbon $fromDay,
        Carbon $toDay,
        array $records,
        string $timezone,
        array $missingDateKeys,
        StockHoldingIntradayReloadRun $run,
        string $holdingName,
    ): int {
        $now = now();
        $sourceUrl = $this->sourceUrl($holding, $fromDay, $toDay);

        $rows = collect($records)
            ->map(function (array $record) use ($holding, $missingDateKeys, $now, $sourceUrl, $timezone): ?array {
                $tradingDate = $this->recordTradingDate($record, $timezone);

                if ($tradingDate === null || ! array_key_exists($tradingDate->toDateString(), $missingDateKeys)) {
                    return null;
                }

                return $this->row($holding, $tradingDate, $record, $sourceUrl, $now);
            })
            ->filter()
            ->values()
            ->all();

        if ($rows === []) {
            $run->update([
                'current' => "Storing data for {$holdingName}",
                'message' => "Storing data for {$holdingName}: 0/0 saved.",
            ]);

            return 0;
        }

        $storedCount = 0;
        $totalCount = count($rows);

        foreach (array_chunk($rows, 100) as $chunk) {
            StockHoldingIntradayCandle::query()->upsert(
                $chunk,
                uniqueBy: ['stock_holding_id', 'interval', 'as_of'],
                update: ['trading_date', 'timestamp', 'gmtoffset', 'datetime', 'open', 'high', 'low', 'close', 'volume', 'currency', 'source_key', 'source_name', 'source_url', 'raw_payload', 'updated_at'],
            );

            $storedCount += count($chunk);
            $run->update([
                'current' => "Storing data for {$holdingName}",
                'message' => "Storing data for {$holdingName}: {$storedCount}/{$totalCount} saved...",
            ]);
        }

        return $storedCount;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function row(StockHolding $holding, Carbon $tradingDate, array $record, string $sourceUrl, Carbon $now): ?array
    {
        $asOf = $this->timestamp(Arr::get($record, 'timestamp'), Arr::get($record, 'datetime'));

        if ($asOf === null) {
            return null;
        }

        $prices = [
            'open' => $this->decimal(Arr::get($record, 'open')),
            'high' => $this->decimal(Arr::get($record, 'high')),
            'low' => $this->decimal(Arr::get($record, 'low')),
            'close' => $this->decimal(Arr::get($record, 'close')),
        ];

        if (! $this->hasPriceData($prices)) {
            $this->deleteCandle($holding, $asOf);

            return null;
        }

        return [
            'stock_holding_id' => $holding->id,
            'trading_date' => $tradingDate->toDateString(),
            'interval' => self::Interval,
            'as_of' => $asOf,
            'timestamp' => $this->integerOrNull(Arr::get($record, 'timestamp')),
            'gmtoffset' => $this->integerOrNull(Arr::get($record, 'gmtoffset')),
            'datetime' => $this->stringOrNull(Arr::get($record, 'datetime')),
            ...$prices,
            'volume' => $this->positiveIntegerOrNull(Arr::get($record, 'volume')),
            'currency' => $holding->currency,
            'source_key' => self::SourceKey,
            'source_name' => 'EODHD intraday',
            'source_url' => $sourceUrl,
            'raw_payload' => json_encode($record),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array{open: ?string, high: ?string, low: ?string, close: ?string}  $prices
     */
    private function hasPriceData(array $prices): bool
    {
        return collect($prices)->contains(fn (?string $price): bool => $price !== null);
    }

    private function deleteCandle(StockHolding $holding, Carbon $asOf): void
    {
        StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->where('interval', self::Interval)
            ->where('as_of', $asOf)
            ->delete();
    }

    private function deleteEmptyPriceRows(StockHolding $holding, Carbon $tradingDate): void
    {
        StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->where('interval', self::Interval)
            ->whereDate('trading_date', $tradingDate->toDateString())
            ->whereNull('open')
            ->whereNull('high')
            ->whereNull('low')
            ->whereNull('close')
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordTradingDate(array $record, string $timezone): ?Carbon
    {
        $asOf = $this->timestamp(Arr::get($record, 'timestamp'), Arr::get($record, 'datetime'));

        if ($asOf === null) {
            return null;
        }

        return $asOf->copy()->setTimezone($timezone)->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    private function candlePayload(StockHoldingIntradayCandle $candle): array
    {
        return [
            'timestamp' => $candle->timestamp,
            'gmtoffset' => $candle->gmtoffset,
            'datetime' => $candle->datetime,
            'open' => $candle->open === null ? null : (string) $candle->open,
            'high' => $candle->high === null ? null : (string) $candle->high,
            'low' => $candle->low === null ? null : (string) $candle->low,
            'close' => $candle->close === null ? null : (string) $candle->close,
            'volume' => $candle->volume,
        ];
    }

    private function sourceUrl(StockHolding $holding, Carbon $fromDay, Carbon $toDay): string
    {
        $from = $fromDay->copy()->startOfDay()->utc()->timestamp;
        $to = $toDay->copy()->endOfDay()->utc()->timestamp;
        $baseUrl = rtrim((string) config('services.eodhd.base_url', 'https://eodhd.com/api'), '/');

        return "{$baseUrl}/intraday/{$this->eodhdSymbol($holding)}?fmt=json&interval=".self::Interval."&from={$from}&to={$to}";
    }

    private function eodhdSymbol(StockHolding $holding): string
    {
        return Str::upper((string) $holding->symbol).'.'.$this->marketData->exchangeCodeForHolding($holding);
    }

    private function exchangeForHolding(StockHolding $holding): ?EodhdExchange
    {
        $exchangeCode = $this->marketData->exchangeCodeForHolding($holding);
        $mic = Str::upper((string) $holding->mic_code);

        return EodhdExchange::query()
            ->where('code', $exchangeCode)
            ->orWhere('detail_code', $mic)
            ->orWhere('operating_mic', $mic)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function holidayLabels(?EodhdExchange $exchange): array
    {
        $holidays = $exchange?->holidays;

        if (! is_array($holidays)) {
            return [];
        }

        if (array_is_list($holidays)) {
            return collect($holidays)
                ->mapWithKeys(function (mixed $holiday): array {
                    if (! is_array($holiday)) {
                        $date = $this->stringOrNull($holiday);

                        return $date ? [$date => 'Holiday'] : [];
                    }

                    $date = $this->stringOrNull($holiday['Date'] ?? $holiday['date'] ?? null);

                    return $date ? [$date => $this->holidayLabel($holiday)] : [];
                })
                ->filter()
                ->all();
        }

        return collect($holidays)
            ->mapWithKeys(fn (mixed $holiday, string $date): array => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
                ? [$date => is_array($holiday) ? $this->holidayLabel($holiday) : (string) ($holiday ?: 'Holiday')]
                : [])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $holiday
     */
    private function holidayLabel(array $holiday): string
    {
        return $this->stringOrNull($holiday['Holiday'] ?? $holiday['Name'] ?? $holiday['name'] ?? null)
            ?? 'Holiday';
    }

    /**
     * @return array<int, string>
     */
    private function workingDays(?EodhdExchange $exchange): array
    {
        $tradingHours = $exchange?->trading_hours;
        $workingDays = is_array($tradingHours) ? ($tradingHours['WorkingDays'] ?? null) : null;

        if (! is_string($workingDays) || trim($workingDays) === '') {
            return ['mon', 'tue', 'wed', 'thu', 'fri'];
        }

        return collect(explode(',', $workingDays))
            ->map(fn (string $day): string => Str::lower(trim($day)))
            ->filter()
            ->values()
            ->all();
    }

    private function displayDate(string $date): string
    {
        try {
            return Carbon::parse($date)->format('d.m.Y');
        } catch (Throwable) {
            return $date;
        }
    }

    private function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value) || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    private function timestamp(mixed $timestamp, mixed $datetime = null): ?Carbon
    {
        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            return Carbon::createFromTimestampUTC((int) $timestamp)
                ->setTimezone(config('app.timezone'));
        }

        if (is_string($datetime) && trim($datetime) !== '') {
            try {
                return Carbon::parse($datetime, 'UTC')
                    ->setTimezone(config('app.timezone'));
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }
}
