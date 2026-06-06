<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayPrice;
use App\Models\StockPrice;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockHoldingIntradayPriceSampler
{
    public function __construct(
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

    public function persistLatestTradingDaySamples(StockHolding $holding, bool $fillMissingSamples = false): void
    {
        $latestRawDate = $this->stockPriceCatalog
            ->pricesForHolding($holding)
            ->whereIn('source_key', EodhdMarketData::sourceKeys())
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->latest('as_of')
            ->value(DB::raw('DATE(as_of)'));

        if ($latestRawDate === null) {
            return;
        }

        $prices = $this->stockPriceCatalog
            ->pricesForHolding($holding)
            ->whereIn('source_key', EodhdMarketData::sourceKeys())
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->whereDate('as_of', $latestRawDate)
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'price', 'currency', 'as_of', 'source_name', 'price_type']);

        if ($fillMissingSamples) {
            $this->persistArraySamples(
                holding: $holding,
                latestRawDate: $latestRawDate,
                prices: $this->sampleArraysExactly(
                    $prices->map(fn (StockPrice $stockPrice): array => $this->stockPricePayload($stockPrice)),
                    20,
                ),
            );

            return;
        }

        $sampledPrices = $this->sampleModels($prices, 20);
        $now = now();
        $rows = $sampledPrices
            ->values()
            ->map(fn (StockPrice $stockPrice, int $index): array => [
                'stock_holding_id' => $holding->id,
                'trading_date' => $latestRawDate,
                'sample_index' => $index,
                'source_stock_price_id' => $stockPrice->id,
                'price' => $stockPrice->price,
                'currency' => $stockPrice->currency,
                'as_of' => $stockPrice->as_of,
                'source_name' => $stockPrice->source_name,
                'price_type' => $stockPrice->price_type,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($this->storedIntradayRecordCount($holding, $latestRawDate) >= count($rows)) {
            return;
        }

        $this->upsertRows($holding, $latestRawDate, $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    public function persistIntradayRecords(StockHolding $holding, array $records): void
    {
        $prices = collect($records)
            ->map(fn (array $record): ?array => $this->intradayRecordPayload($holding, $record))
            ->filter()
            ->sortBy([
                ['as_of', 'asc'],
                ['price', 'asc'],
            ])
            ->values();

        if ($prices->isEmpty()) {
            return;
        }

        $latestRawDate = Carbon::parse((string) $prices->last()['as_of'], 'UTC')->toDateString();
        $sampledPrices = $this->sampleArraysExactly(
            $prices
                ->filter(fn (array $price): bool => Carbon::parse((string) $price['as_of'], 'UTC')->toDateString() === $latestRawDate)
                ->values(),
            20,
        );

        $this->persistArraySamples($holding, $latestRawDate, $sampledPrices);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $prices
     */
    private function persistArraySamples(StockHolding $holding, string $latestRawDate, Collection $prices): void
    {
        if ($prices->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $prices
            ->values()
            ->map(fn (array $price, int $index): array => [
                ...$price,
                'stock_holding_id' => $holding->id,
                'trading_date' => $latestRawDate,
                'sample_index' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        $this->upsertRows($holding, $latestRawDate, $rows);
    }

    /**
     * @return array{source_stock_price_id: int, price: mixed, currency: ?string, as_of: mixed, source_name: ?string, price_type: ?string}
     */
    private function stockPricePayload(StockPrice $stockPrice): array
    {
        return [
            'source_stock_price_id' => $stockPrice->id,
            'price' => $stockPrice->price,
            'currency' => $stockPrice->currency,
            'as_of' => $stockPrice->as_of,
            'source_name' => $stockPrice->source_name,
            'price_type' => $stockPrice->price_type,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertRows(StockHolding $holding, string $latestRawDate, array $rows): void
    {
        DB::transaction(function () use ($holding, $latestRawDate, $rows): void {
            StockHoldingIntradayPrice::query()
                ->where('stock_holding_id', $holding->id)
                ->whereDate('trading_date', $latestRawDate)
                ->delete();

            StockHoldingIntradayPrice::query()->insert($rows);
        });
    }

    private function storedIntradayRecordCount(StockHolding $holding, string $latestRawDate): int
    {
        return StockHoldingIntradayPrice::query()
            ->where('stock_holding_id', $holding->id)
            ->whereDate('trading_date', $latestRawDate)
            ->where('source_name', 'EODHD intraday')
            ->count();
    }

    /**
     * @return array{source_stock_price_id: null, price: string, currency: ?string, as_of: Carbon, source_name: string, price_type: string}|null
     */
    private function intradayRecordPayload(StockHolding $holding, array $record): ?array
    {
        $asOf = $this->recordTimestamp(Arr::get($record, 'timestamp'), Arr::get($record, 'datetime'));
        $price = $this->recordDecimal(Arr::get($record, 'close'));

        if ($asOf === null || $price === null) {
            return null;
        }

        return [
            'source_stock_price_id' => null,
            'price' => $price,
            'currency' => $holding->currency,
            'as_of' => $asOf,
            'source_name' => 'EODHD intraday',
            'price_type' => 'intraday',
        ];
    }

    private function recordTimestamp(mixed $timestamp, mixed $datetime): ?Carbon
    {
        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestamp((int) $timestamp, 'UTC');
        }

        if (is_string($datetime) && trim($datetime) !== '') {
            return Carbon::parse($datetime, 'UTC');
        }

        return null;
    }

    private function recordDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    /**
     * @return Collection<int, StockPrice>
     */
    private function sampleModels(Collection $prices, int $maximumPoints): Collection
    {
        if ($prices->count() <= $maximumPoints) {
            return $prices->values();
        }

        $lastIndex = $prices->count() - 1;
        $prices = $prices->values();

        return collect(range(0, $maximumPoints - 1))
            ->map(fn (int $index): int => (int) round(($index / ($maximumPoints - 1)) * $lastIndex))
            ->unique()
            ->values()
            ->map(fn (int $index): StockPrice => $prices->get($index))
            ->filter()
            ->values();
    }

    private function sampleArraysExactly(Collection $prices, int $maximumPoints): Collection
    {
        if ($prices->count() === 0) {
            return collect();
        }

        if ($prices->count() === $maximumPoints) {
            return $prices->values();
        }

        $lastIndex = $prices->count() - 1;
        $prices = $prices->values();

        return collect(range(0, $maximumPoints - 1))
            ->map(fn (int $index): int => (int) round(($index / ($maximumPoints - 1)) * $lastIndex))
            ->map(fn (int $index): array => $prices->get($index))
            ->filter()
            ->values();
    }
}
