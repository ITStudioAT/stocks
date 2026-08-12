<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class EodhdHistoricalDataService
{
    public const MaxAnalysisRowCount = 1000;

    public function __construct(
        private EodhdApiClient $apiClient,
        private EodhdErrorSanitizer $errorSanitizer,
        private EodhdMarketData $marketData,
        private StockPriceCatalog $stockPriceCatalog,
        private CompletedTradingDay $completedTradingDay,
    ) {}

    /**
     * @return array{
     *     requested_row_count: int,
     *     required_price_count: int,
     *     holding_count: int,
     *     missing_holding_count: int,
     *     minimum_available_row_count: int,
     *     is_complete: bool,
     *     holdings: array<int, array{id: int, label: string, stored_price_count: int, available_row_count: int, missing_price_count: int}>,
     * }
     */
    public function rowCoverage(int $rowCount): array
    {
        $this->validateAnalysisRowCount($rowCount);

        return $this->rowCoverageForHoldings($this->holdingsWithStoredPriceCounts(), $rowCount);
    }

    /**
     * @return array{
     *     requested_count: int,
     *     stored_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     errors: array<int, string>,
     *     coverage: array<string, mixed>,
     * }
     */
    public function ensureRows(int $rowCount): array
    {
        $this->validateAnalysisRowCount($rowCount);

        $requiredPriceCount = $rowCount + 1;
        $holdings = $this->holdingsWithStoredPriceCounts();
        $requestedCount = 0;
        $storedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($holdings as $holding) {
            if ((int) $holding->stored_price_count >= $requiredPriceCount) {
                $skippedCount++;

                continue;
            }

            try {
                $result = Cache::lock("eodhd-historical-row-backfill:{$holding->id}", 120)
                    ->block(5, fn (): array => $this->backfillHoldingRows($holding, $requiredPriceCount));

                $requestedCount += $result['requested_count'];
                $storedCount += $result['stored_count'];
                $skippedCount += $result['skipped_count'];
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = $this->errorSanitizer->message($exception->getMessage());

                if ($exception->getCode() === 401) {
                    break;
                }
            }
        }

        return [
            'requested_count' => $requestedCount,
            'stored_count' => $storedCount,
            'skipped_count' => $skippedCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
            'coverage' => $this->rowCoverage($rowCount),
        ];
    }

    /**
     * @return array{
     *     requested_count: int,
     *     stored_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     target_date: string,
     *     errors: array<int, string>,
     * }
     */
    public function syncAll(Carbon $targetDate): array
    {
        return $this->syncHoldings($this->holdings(), $targetDate);
    }

    /**
     * @param  Collection<int, StockHolding>  $holdings
     * @return array{
     *     requested_count: int,
     *     stored_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     target_date: string,
     *     errors: array<int, string>,
     * }
     */
    public function syncHoldings(Collection $holdings, Carbon $targetDate): array
    {
        $requestedCount = 0;
        $storedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($holdings as $holding) {
            try {
                $result = $this->syncHolding($holding, $targetDate);
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = $this->errorSanitizer->message($exception->getMessage());

                if ($exception->getCode() === 401) {
                    break;
                }

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
            'target_date' => $targetDate->toDateString(),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{requested_count: int, stored_count: int, skipped_count: int, date_from: ?string, date_to: string}
     */
    public function syncHolding(StockHolding $holding, Carbon $targetDate): array
    {
        $dateTo = $targetDate->copy()->startOfDay();
        $dateFrom = $this->nextMissingDate($holding);

        if ($dateFrom->gt($dateTo)) {
            return [
                'requested_count' => 0,
                'stored_count' => 0,
                'skipped_count' => 1,
                'date_from' => null,
                'date_to' => $dateTo->toDateString(),
            ];
        }

        return [
            'requested_count' => 1,
            'stored_count' => $this->storePrices($holding, $dateFrom, $dateTo),
            'skipped_count' => 0,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
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
     * @return Collection<int, StockHolding>
     */
    private function holdingsWithStoredPriceCounts(): Collection
    {
        return StockHolding::query()
            ->select(['id', 'name', 'subtitle', 'isin', 'wkn', 'symbol', 'exchange', 'mic_code', 'currency', 'trading_times'])
            ->withCount([
                'dailyPrices as stored_price_count' => fn ($query) => $query
                    ->where(fn ($query) => $query
                        ->whereNotNull('adjusted_close')
                        ->orWhereNotNull('close')),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, StockHolding>  $holdings
     * @return array{
     *     requested_row_count: int,
     *     required_price_count: int,
     *     holding_count: int,
     *     missing_holding_count: int,
     *     minimum_available_row_count: int,
     *     is_complete: bool,
     *     holdings: array<int, array{id: int, label: string, stored_price_count: int, available_row_count: int, missing_price_count: int}>,
     * }
     */
    private function rowCoverageForHoldings(Collection $holdings, int $rowCount): array
    {
        $requiredPriceCount = $rowCount + 1;
        $coverageHoldings = $holdings
            ->map(function (StockHolding $holding) use ($requiredPriceCount): array {
                $storedPriceCount = (int) $holding->stored_price_count;

                return [
                    'id' => $holding->id,
                    'label' => collect([$holding->symbol, $holding->name, $holding->subtitle])->filter()->implode(' - '),
                    'stored_price_count' => $storedPriceCount,
                    'available_row_count' => max($storedPriceCount - 1, 0),
                    'missing_price_count' => max($requiredPriceCount - $storedPriceCount, 0),
                ];
            })
            ->values();
        $missingHoldingCount = $coverageHoldings
            ->where('missing_price_count', '>', 0)
            ->count();

        return [
            'requested_row_count' => $rowCount,
            'required_price_count' => $requiredPriceCount,
            'holding_count' => $coverageHoldings->count(),
            'missing_holding_count' => $missingHoldingCount,
            'minimum_available_row_count' => (int) ($coverageHoldings->min('available_row_count') ?? 0),
            'is_complete' => $missingHoldingCount === 0,
            'holdings' => $coverageHoldings->all(),
        ];
    }

    /**
     * @return array{requested_count: int, stored_count: int, skipped_count: int}
     */
    private function backfillHoldingRows(StockHolding $holding, int $requiredPriceCount): array
    {
        $storedPriceCount = $this->storedPriceCount($holding);

        if ($storedPriceCount >= $requiredPriceCount) {
            return [
                'requested_count' => 0,
                'stored_count' => 0,
                'skipped_count' => 1,
            ];
        }

        $missingPriceCount = $requiredPriceCount - $storedPriceCount;
        $earliestTradingDate = StockHoldingDailyPrice::query()
            ->where('stock_holding_id', $holding->id)
            ->where(fn ($query) => $query
                ->whereNotNull('adjusted_close')
                ->orWhereNotNull('close'))
            ->min('trading_date');
        $dateTo = $earliestTradingDate === null
            ? $this->completedTradingDay->date()
            : Carbon::parse($earliestTradingDate, 'Europe/Vienna')->subDay()->startOfDay();
        $dateFrom = $dateTo->copy()->subDays(max(45, ($missingPriceCount * 2) + 14));

        $this->storePrices($holding, $dateFrom, $dateTo, $missingPriceCount);
        $updatedStoredPriceCount = $this->storedPriceCount($holding);

        return [
            'requested_count' => 1,
            'stored_count' => max($updatedStoredPriceCount - $storedPriceCount, 0),
            'skipped_count' => 0,
        ];
    }

    private function storedPriceCount(StockHolding $holding): int
    {
        return StockHoldingDailyPrice::query()
            ->where('stock_holding_id', $holding->id)
            ->where(fn ($query) => $query
                ->whereNotNull('adjusted_close')
                ->orWhereNotNull('close'))
            ->count();
    }

    private function validateAnalysisRowCount(int $rowCount): void
    {
        if ($rowCount < 1 || $rowCount > self::MaxAnalysisRowCount) {
            throw new InvalidArgumentException('The requested analysis row count must be between 1 and 1,000.');
        }
    }

    private function nextMissingDate(StockHolding $holding): Carbon
    {
        $latestAsOf = $this->stockPriceCatalog->pricesForHolding($holding)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->max('as_of');
        $latestDailyDate = StockHoldingDailyPrice::query()
            ->where('stock_holding_id', $holding->id)
            ->max('trading_date');

        if ($latestAsOf === null && $latestDailyDate === null) {
            return now('Europe/Vienna')->subYear()->startOfDay();
        }

        $nextStockPriceDate = $latestAsOf === null
            ? null
            : Carbon::parse($latestAsOf, 'UTC')->setTimezone('Europe/Vienna')->addDay()->startOfDay();
        $nextDailyPriceDate = $latestDailyDate === null
            ? null
            : Carbon::parse($latestDailyDate, 'Europe/Vienna')->addDay()->startOfDay();

        return collect([$nextStockPriceDate, $nextDailyPriceDate])
            ->filter()
            ->sortBy(fn (Carbon $date): int => $date->timestamp)
            ->first();
    }

    private function storePrices(
        StockHolding $holding,
        Carbon $dateFrom,
        Carbon $dateTo,
        ?int $maximumRecordCount = null,
    ): int {
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
            throw new RuntimeException("EODHD historical request failed with HTTP {$response->status()} for {$symbol}.");
        }

        $payload = $this->errorSanitizer->payload($response->json());

        if (! is_array($payload)) {
            throw new RuntimeException("EODHD returned an invalid historical response for {$symbol}.");
        }

        if (($payload['status'] ?? null) === 'error') {
            throw new RuntimeException($this->errorSanitizer->message(
                (string) ($payload['message'] ?? "EODHD returned an error response for {$symbol}."),
            ));
        }

        $instrumentKey = $this->stockPriceCatalog->instrumentKeyForHolding($holding);
        $sourceUrl = $this->sourceUrl($symbol, $exchangeCode, $dateFrom, $dateTo);
        $now = now();
        $storedCount = 0;
        $dateFromString = $dateFrom->toDateString();
        $dateToString = $dateTo->toDateString();

        $records = collect($payload)
            ->filter(function (mixed $record) use ($dateFromString, $dateToString): bool {
                if (! is_array($record) || ! is_numeric($record['close'] ?? null) || ! is_string($record['date'] ?? null)) {
                    return false;
                }

                $tradingDate = $this->date($record['date']);

                return $tradingDate !== null && $tradingDate >= $dateFromString && $tradingDate <= $dateToString;
            })
            ->sortBy(fn (array $record): string => $record['date'])
            ->when(
                $maximumRecordCount !== null,
                fn (Collection $records): Collection => $records->take(-max($maximumRecordCount, 0)),
            );

        foreach ($records as $record) {
            $row = $this->stockPriceRow($holding, $instrumentKey, $symbol, $sourceUrl, $record, $now);
            $dailyPriceRow = $this->dailyPriceRow($holding, $sourceUrl, $record, $now);

            if ($row === null || $dailyPriceRow === null) {
                continue;
            }

            $storedCount += StockPrice::query()->insertOrIgnore([$row]);
            StockHoldingDailyPrice::query()->upsert(
                [$dailyPriceRow],
                uniqueBy: ['stock_holding_id', 'trading_date'],
                update: ['open', 'high', 'low', 'close', 'adjusted_close', 'volume', 'currency', 'source_key', 'source_name', 'source_url', 'raw_payload', 'updated_at'],
            );
        }

        return $storedCount;
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

    /**
     * @return array<string, mixed>|null
     */
    private function dailyPriceRow(StockHolding $holding, string $sourceUrl, mixed $record, Carbon $now): ?array
    {
        if (! is_array($record) || ! is_numeric($record['close'] ?? null) || ! is_string($record['date'] ?? null)) {
            return null;
        }

        $tradingDate = $this->date($record['date']);

        if ($tradingDate === null) {
            return null;
        }

        return [
            'stock_holding_id' => $holding->id,
            'trading_date' => $tradingDate,
            'open' => $this->decimal($record['open'] ?? null),
            'high' => $this->decimal($record['high'] ?? null),
            'low' => $this->decimal($record['low'] ?? null),
            'close' => $this->decimal($record['close']),
            'adjusted_close' => $this->decimal($record['adjusted_close'] ?? null),
            'volume' => $this->integer($record['volume'] ?? null),
            'currency' => $this->upperIdentifier($holding->currency, 3),
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'source_url' => $sourceUrl,
            'raw_payload' => json_encode($record, JSON_THROW_ON_ERROR),
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

    private function integer(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function upperIdentifier(?string $value, int $limit): ?string
    {
        $value = Str::upper(trim((string) $value));

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
