<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class StockEndOfDayRepairService
{
    public function __construct(
        private EodhdApiClient $apiClient,
        private EodhdMarketData $marketData,
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

    /**
     * @return array{
     *     minimum_date: string,
     *     actual_date: ?string,
     *     total_stocks_count: int,
     *     covered_stocks_count: int,
     *     missing_stocks_count: int,
     *     missing_stocks: array<int, array{id: int, label: string}>,
     * }
     */
    public function summary(): array
    {
        $minimumDateEnd = now()->subYear()->endOfDay();
        $instrumentKeys = $this->holdings()
            ->map(fn (StockHolding $holding): string => $this->stockPriceCatalog->instrumentKeyForHolding($holding));
        $uniqueInstrumentKeys = $instrumentKeys->unique()->values();
        $coveredInstrumentKeys = StockPrice::query()
            ->whereIn('instrument_key', $uniqueInstrumentKeys)
            ->whereNotNull('price')
            ->where('as_of', '<=', $minimumDateEnd)
            ->distinct()
            ->pluck('instrument_key')
            ->flip();
        $coveredStocksCount = $instrumentKeys
            ->filter(fn (string $instrumentKey): bool => $coveredInstrumentKeys->has($instrumentKey))
            ->count();
        $totalStocksCount = $instrumentKeys->count();

        return [
            'minimum_date' => now()->subYear()->toDateString(),
            'actual_date' => $this->actualDate($uniqueInstrumentKeys),
            'total_stocks_count' => $totalStocksCount,
            'covered_stocks_count' => $coveredStocksCount,
            'missing_stocks_count' => $totalStocksCount - $coveredStocksCount,
            'missing_stocks' => $this->missingStocksPayload(),
        ];
    }

    /**
     * @return array{
     *     message: string,
     *     repaired_stocks_count: int,
     *     stored_prices_count: int,
     *     repair: array{end_of_day: array<string, mixed>},
     * }
     */
    public function repair(): array
    {
        $dateFrom = now()->subYear()->toDateString();
        $dateTo = now()->toDateString();
        $holdings = $this->missingHoldings();
        $storedCount = 0;

        foreach ($holdings as $holding) {
            foreach ($this->missingDateRanges($holding, Carbon::parse($dateFrom), Carbon::parse($dateTo)) as $range) {
                $storedCount += $this->storeEndOfDayPricesFromEodhd(
                    $holding,
                    $range['from']->toDateString(),
                    $range['to']->toDateString(),
                );
            }
        }

        return [
            'message' => trans_choice('{0} No missing stocks found.|{1} 1 stock repaired.|[2,*] :count stocks repaired.', $holdings->count()),
            'repaired_stocks_count' => $holdings->count(),
            'stored_prices_count' => $storedCount,
            'repair' => [
                'end_of_day' => $this->summary(),
            ],
        ];
    }

    /**
     * @return array{stock: array{id: int, label: string}, stored_prices_count: int}
     */
    public function repairHolding(StockHolding $holding): array
    {
        $dateFrom = now()->subYear();
        $dateTo = now();
        $storedCount = 0;

        if (! $this->isMissing($holding)) {
            return [
                'stock' => [
                    'id' => $holding->id,
                    'label' => $this->holdingLabel($holding),
                ],
                'stored_prices_count' => 0,
            ];
        }

        foreach ($this->missingDateRanges($holding, $dateFrom, $dateTo) as $range) {
            $storedCount += $this->storeEndOfDayPricesFromEodhd(
                $holding,
                $range['from']->toDateString(),
                $range['to']->toDateString(),
            );
        }

        return [
            'stock' => [
                'id' => $holding->id,
                'label' => $this->holdingLabel($holding),
            ],
            'stored_prices_count' => $storedCount,
        ];
    }

    /**
     * @return Collection<int, StockHolding>
     */
    private function missingHoldings(): Collection
    {
        $minimumDateEnd = now()->subYear()->endOfDay();
        $holdings = $this->holdings();
        $instrumentKeys = $holdings
            ->map(fn (StockHolding $holding): string => $this->stockPriceCatalog->instrumentKeyForHolding($holding))
            ->unique()
            ->values();
        $coveredInstrumentKeys = StockPrice::query()
            ->whereIn('instrument_key', $instrumentKeys)
            ->whereNotNull('price')
            ->where('as_of', '<=', $minimumDateEnd)
            ->distinct()
            ->pluck('instrument_key')
            ->flip();

        return $holdings
            ->filter(fn (StockHolding $holding): bool => ! $coveredInstrumentKeys->has($this->stockPriceCatalog->instrumentKeyForHolding($holding)))
            ->unique('id')
            ->values();
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    private function missingStocksPayload(): array
    {
        return $this->missingHoldings()
            ->map(fn (StockHolding $holding): array => [
                'id' => $holding->id,
                'label' => $this->holdingLabel($holding),
            ])
            ->values()
            ->all();
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

    private function isMissing(StockHolding $holding): bool
    {
        return ! $this->stockPriceCatalog->pricesForHolding($holding)
            ->whereNotNull('price')
            ->where('as_of', '<=', now()->subYear()->endOfDay())
            ->exists();
    }

    private function storeEndOfDayPricesFromEodhd(StockHolding $holding, string $dateFrom, string $dateTo): int
    {
        $symbol = Str::upper((string) $holding->symbol);

        if ($symbol === '') {
            return 0;
        }

        $exchangeCode = $this->marketData->exchangeCodeForHolding($holding);

        if ($exchangeCode === '') {
            throw new RuntimeException("Missing EODHD exchange code for {$symbol}.");
        }

        $response = $this->apiClient->get("eod/{$symbol}.{$exchangeCode}", [
            'from' => $dateFrom,
            'to' => $dateTo,
            'period' => 'd',
            'fmt' => 'json',
        ]);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException("EODHD returned an invalid EOD response for {$symbol}.");
        }

        if (($payload['status'] ?? null) === 'error') {
            throw new RuntimeException((string) ($payload['message'] ?? "EODHD returned an error response for {$symbol}."));
        }

        $instrumentKey = $this->stockPriceCatalog->instrumentKeyForHolding($holding);
        $now = now();
        $storedCount = 0;

        foreach ($payload as $record) {
            if (! is_array($record) || ! is_numeric($record['close'] ?? null) || ! is_string($record['date'] ?? null)) {
                continue;
            }

            $tradingDate = Carbon::parse($record['date'])->toDateString();
            $price = number_format((float) $record['close'], 8, '.', '');

            $storedCount += StockPrice::query()->insertOrIgnore([
                [
                    'quote_hash' => hash('sha256', "{$instrumentKey}|eodhd_eod|{$tradingDate}|close"),
                    'instrument_key' => $instrumentKey,
                    'source_key' => 'eodhd_eod',
                    'source_name' => 'EODHD EOD',
                    'source_url' => $this->sourceUrl($symbol, $exchangeCode, $dateFrom, $dateTo),
                    'source_quality' => 'official_venue',
                    'venue' => $holding->exchange,
                    'mic' => $holding->mic_code,
                    'isin' => $holding->isin,
                    'wkn' => $holding->wkn,
                    'symbol' => $symbol,
                    'currency' => $this->currency($holding->currency),
                    'close' => $price,
                    'price' => $price,
                    'price_type' => 'historical_eod',
                    'as_of' => Carbon::parse($tradingDate, 'UTC')->endOfDay(),
                    'fetched_at' => $now,
                    'freshness_status' => 'historical',
                    'validation_status' => 'valid',
                    'validation_errors' => [],
                    'raw_payload' => $record,
                    'trading_times' => $holding->trading_times,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }

        return $storedCount;
    }

    /**
     * @return array<int, array{from: Carbon, to: Carbon}>
     */
    private function missingDateRanges(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): array
    {
        $storedDates = $this->stockPriceCatalog->pricesForHolding($holding)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $dateFrom->copy()->startOfDay())
            ->where('as_of', '<=', $dateTo->copy()->endOfDay())
            ->pluck('as_of')
            ->map(fn (mixed $asOf): string => Carbon::parse($asOf)->toDateString())
            ->flip();
        $ranges = [];
        $rangeStart = null;
        $previousMissingDate = null;
        $date = $dateFrom->copy()->startOfDay();

        while ($date->lte($dateTo)) {
            if (! $date->isWeekday()) {
                $date->addDay();

                continue;
            }

            if ($storedDates->has($date->toDateString())) {
                if ($rangeStart !== null && $previousMissingDate !== null) {
                    $ranges[] = [
                        'from' => $rangeStart->copy(),
                        'to' => $previousMissingDate->copy(),
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
                'from' => $rangeStart->copy(),
                'to' => $previousMissingDate->copy(),
            ];
        }

        return $ranges;
    }

    private function sourceUrl(string $symbol, string $exchangeCode, string $dateFrom, string $dateTo): string
    {
        $baseUrl = rtrim((string) config('services.eodhd.base_url', 'https://eodhd.com/api'), '/');

        return "{$baseUrl}/eod/{$symbol}.{$exchangeCode}?from={$dateFrom}&to={$dateTo}&period=d&fmt=json";
    }

    private function holdingLabel(StockHolding $holding): string
    {
        return collect([$holding->symbol, $holding->name])->filter()->implode(' - ');
    }

    /**
     * @param  Collection<int, string>  $instrumentKeys
     */
    private function actualDate(Collection $instrumentKeys): ?string
    {
        $firstStoredDate = StockPrice::query()
            ->whereIn('instrument_key', $instrumentKeys)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->min('as_of');

        return $firstStoredDate === null
            ? null
            : Carbon::parse($firstStoredDate)->toDateString();
    }

    private function currency(?string $currency): ?string
    {
        $currency = Str::upper(trim((string) $currency));

        return $currency !== '' ? Str::limit($currency, 3, '') : null;
    }
}
