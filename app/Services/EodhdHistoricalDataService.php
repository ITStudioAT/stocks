<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EodhdHistoricalDataService
{
    public function __construct(
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

    private function storePrices(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): int
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
            throw new RuntimeException("EODHD historical request failed with HTTP {$response->status()} for {$symbol}.");
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException("EODHD returned an invalid historical response for {$symbol}.");
        }

        if (($payload['status'] ?? null) === 'error') {
            throw new RuntimeException((string) ($payload['message'] ?? "EODHD returned an error response for {$symbol}."));
        }

        $instrumentKey = $this->stockPriceCatalog->instrumentKeyForHolding($holding);
        $sourceUrl = $this->sourceUrl($symbol, $exchangeCode, $dateFrom, $dateTo);
        $now = now();
        $storedCount = 0;

        foreach ($payload as $record) {
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
