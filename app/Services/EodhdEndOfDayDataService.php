<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EodhdEndOfDayDataService
{
    public function __construct(
        private CompletedTradingDay $completedTradingDay,
        private EodhdApiClient $apiClient,
        private EodhdMarketData $marketData,
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

    /**
     * @return array{
     *     requested_count: int,
     *     stored_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     date_from: string,
     *     date_to: string,
     *     errors: array<int, string>,
     * }
     */
    public function syncAll(?Carbon $targetDate = null): array
    {
        $dateTo = ($targetDate ?? $this->completedTradingDay->date())->copy()->startOfDay();
        $dateFrom = $dateTo->copy()->subYear()->startOfDay();

        return $this->syncHoldings($this->holdings(), $dateFrom, $dateTo);
    }

    /**
     * @return array{
     *     requested_count: int,
     *     stored_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     date_from: string,
     *     date_to: string,
     *     errors: array<int, string>,
     * }
     */
    public function syncLatestMissing(): array
    {
        $targetDate = $this->completedTradingDay->date()->startOfDay();

        return $this->reloadHoldings($this->holdings(), $targetDate, $targetDate);
    }

    /**
     * @param  Collection<int, StockHolding>  $holdings
     * @return array{
     *     requested_count: int,
     *     stored_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     date_from: string,
     *     date_to: string,
     *     errors: array<int, string>,
     * }
     */
    public function syncHoldings(Collection $holdings, Carbon $dateFrom, Carbon $dateTo): array
    {
        return $this->processHoldings($holdings, $dateFrom, $dateTo, false);
    }

    /**
     * @param  Collection<int, StockHolding>  $holdings
     * @return array{
     *     requested_count: int,
     *     stored_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     date_from: string,
     *     date_to: string,
     *     errors: array<int, string>,
     * }
     */
    public function reloadHoldings(Collection $holdings, Carbon $dateFrom, Carbon $dateTo): array
    {
        return $this->processHoldings($holdings, $dateFrom, $dateTo, true);
    }

    /**
     * @param  Collection<int, StockHolding>  $holdings
     * @return array{
     *     requested_count: int,
     *     stored_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     date_from: string,
     *     date_to: string,
     *     errors: array<int, string>,
     * }
     */
    private function processHoldings(Collection $holdings, Carbon $dateFrom, Carbon $dateTo, bool $reloadStored): array
    {
        $requestedCount = 0;
        $storedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($holdings as $holding) {
            try {
                $result = $this->processHolding($holding, $dateFrom, $dateTo, $reloadStored);
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = $exception->getMessage();

                continue;
            }

            $requestedCount += $result['requested_count'];
            $storedCount += $result['stored_count'];
            $skippedCount += $result['skipped_count'];
        }

        return [
            'requested_count' => $requestedCount,
            'stored_count' => $storedCount,
            'skipped_count' => $skippedCount,
            'failed_count' => $failedCount,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{requested_count: int, stored_count: int, skipped_count: int, ranges: array<int, array{from: string, to: string}>}
     */
    public function syncHolding(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): array
    {
        return $this->processHolding($holding, $dateFrom, $dateTo, false);
    }

    /**
     * @return array{requested_count: int, stored_count: int, skipped_count: int, ranges: array<int, array{from: string, to: string}>}
     */
    public function reloadHolding(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): array
    {
        return $this->processHolding($holding, $dateFrom, $dateTo, true);
    }

    /**
     * @return array{requested_count: int, stored_count: int, skipped_count: int, ranges: array<int, array{from: string, to: string}>}
     */
    private function processHolding(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo, bool $reloadStored): array
    {
        $dateFrom = $dateFrom->copy()->startOfDay();
        $dateTo = $dateTo->copy()->startOfDay();

        if ($dateFrom->gt($dateTo)) {
            return [
                'requested_count' => 0,
                'stored_count' => 0,
                'skipped_count' => 1,
                'ranges' => [],
            ];
        }

        $missingRanges = $reloadStored
            ? [['from' => $dateFrom->toDateString(), 'to' => $dateTo->toDateString()]]
            : $this->missingRanges($holding, $dateFrom, $dateTo);

        if ($missingRanges === []) {
            return [
                'requested_count' => 0,
                'stored_count' => 0,
                'skipped_count' => 1,
                'ranges' => [],
            ];
        }

        $storedCount = 0;

        foreach ($missingRanges as $missingRange) {
            $storedCount += $this->fetchAndStoreRange(
                $holding,
                Carbon::parse($missingRange['from'], 'Europe/Vienna'),
                Carbon::parse($missingRange['to'], 'Europe/Vienna'),
            );
        }

        return [
            'requested_count' => count($missingRanges),
            'stored_count' => $storedCount,
            'skipped_count' => 0,
            'ranges' => $missingRanges,
        ];
    }

    /**
     * @return Collection<int, StockHolding>
     */
    private function holdings(): Collection
    {
        return StockHolding::query()
            ->orderBy('id')
            ->get(['id', 'name', 'isin', 'wkn', 'symbol', 'exchange', 'mic_code', 'currency', 'trading_times']);
    }

    /**
     * @return array<int, array{from: string, to: string}>
     */
    private function missingRanges(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): array
    {
        $storedDates = $this->storedDates($holding, $dateFrom, $dateTo);
        $ranges = [];
        $rangeStart = null;
        $previousMissingDate = null;
        $date = $dateFrom->copy();

        while ($date->lte($dateTo)) {
            if ($date->isWeekend()) {
                $date->addDay();

                continue;
            }

            $dateString = $date->toDateString();

            if ($storedDates->has($dateString)) {
                if ($rangeStart !== null && $previousMissingDate !== null) {
                    $ranges[] = [
                        'from' => $rangeStart,
                        'to' => $previousMissingDate,
                    ];
                    $rangeStart = null;
                    $previousMissingDate = null;
                }

                $date->addDay();

                continue;
            }

            $rangeStart ??= $dateString;
            $previousMissingDate = $dateString;
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

    /**
     * @return Collection<string, int>
     */
    private function storedDates(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): Collection
    {
        $instrumentKey = $this->stockPriceCatalog->instrumentKeyForHolding($holding);

        return StockPrice::query()
            ->where('instrument_key', $instrumentKey)
            ->where('source_key', 'eodhd_eod')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->whereBetween('as_of', [
                $dateFrom->copy()->startOfDay()->utc(),
                $dateTo->copy()->endOfDay()->utc(),
            ])
            ->pluck('as_of')
            ->map(fn (mixed $asOf): string => Carbon::parse($asOf, 'UTC')->setTimezone('Europe/Vienna')->toDateString())
            ->unique()
            ->flip();
    }

    private function fetchAndStoreRange(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): int
    {
        $symbol = $this->symbol($holding);
        $exchangeCode = $this->marketData->exchangeCodeForHolding($holding);

        if ($symbol === '') {
            return 0;
        }

        if ($exchangeCode === '') {
            throw new RuntimeException("Missing EODHD exchange code for {$symbol}.");
        }

        $response = $this->apiClient->get("eod/{$symbol}.{$exchangeCode}", [
            'from' => $dateFrom->toDateString(),
            'to' => $dateTo->toDateString(),
            'period' => 'd',
            'fmt' => 'json',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("EODHD end-of-day request failed with HTTP {$response->status()} for {$symbol}.");
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException("EODHD returned an invalid end-of-day response for {$symbol}.");
        }

        if (($payload['status'] ?? null) === 'error') {
            throw new RuntimeException((string) ($payload['message'] ?? "EODHD returned an error response for {$symbol}."));
        }

        $instrumentKey = $this->stockPriceCatalog->instrumentKeyForHolding($holding);
        $sourceUrl = $this->sourceUrl($symbol, $exchangeCode, $dateFrom, $dateTo);
        $now = now();
        $rows = collect($payload)
            ->map(fn (mixed $record): ?array => $this->stockPriceRow($holding, $instrumentKey, $symbol, $sourceUrl, $record, $now))
            ->filter()
            ->values()
            ->all();

        if ($rows === []) {
            return 0;
        }

        return DB::transaction(function () use ($rows): int {
            StockPrice::query()->upsert(
                $rows,
                uniqueBy: ['quote_hash'],
                update: [
                    'instrument_key',
                    'source_key',
                    'source_name',
                    'source_url',
                    'source_quality',
                    'venue',
                    'mic',
                    'isin',
                    'wkn',
                    'symbol',
                    'currency',
                    'close',
                    'price',
                    'price_type',
                    'as_of',
                    'fetched_at',
                    'freshness_status',
                    'validation_status',
                    'validation_errors',
                    'raw_payload',
                    'trading_times',
                    'updated_at',
                ],
            );

            return count($rows);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stockPriceRow(
        StockHolding $holding,
        string $instrumentKey,
        string $symbol,
        string $sourceUrl,
        mixed $record,
        Carbon $now,
    ): ?array {
        if (! is_array($record) || ! is_numeric($record['close'] ?? null) || ! is_string($record['date'] ?? null)) {
            return null;
        }

        $tradingDate = $this->date($record['date']);

        if ($tradingDate === null) {
            return null;
        }

        $price = $this->decimal($record['close']);

        return [
            'quote_hash' => hash('sha256', "{$instrumentKey}|eodhd_eod|{$tradingDate}|close"),
            'instrument_key' => $instrumentKey,
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'source_url' => $sourceUrl,
            'source_quality' => 'official_venue',
            'venue' => $holding->exchange,
            'mic' => $holding->mic_code,
            'isin' => $this->upperIdentifier($holding->isin, 12),
            'wkn' => $this->upperIdentifier($holding->wkn, 6),
            'symbol' => $symbol,
            'currency' => $this->upperIdentifier($holding->currency, 3),
            'close' => $price,
            'price' => $price,
            'price_type' => 'historical_eod',
            'as_of' => Carbon::parse($tradingDate, 'Europe/Vienna')->endOfDay()->utc(),
            'fetched_at' => $now,
            'freshness_status' => 'historical',
            'validation_status' => 'valid',
            'validation_errors' => json_encode([], JSON_THROW_ON_ERROR),
            'raw_payload' => json_encode($record, JSON_THROW_ON_ERROR),
            'trading_times' => $holding->trading_times,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function sourceUrl(string $symbol, string $exchangeCode, Carbon $dateFrom, Carbon $dateTo): string
    {
        $baseUrl = rtrim((string) config('services.eodhd.base_url', 'https://eodhd.com/api'), '/');

        return "{$baseUrl}/eod/{$symbol}.{$exchangeCode}?from={$dateFrom->toDateString()}&to={$dateTo->toDateString()}&period=d&fmt=json";
    }

    private function symbol(StockHolding $holding): string
    {
        return Str::upper(trim((string) $holding->symbol));
    }

    private function date(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function decimal(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    private function upperIdentifier(?string $value, int $limit): ?string
    {
        $value = Str::upper(trim((string) $value));

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
