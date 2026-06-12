<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayPrice;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockHoldingIntradayPriceSampler
{
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
        $latestDatePrices = $prices
            ->filter(fn (array $price): bool => Carbon::parse((string) $price['as_of'], 'UTC')->toDateString() === $latestRawDate)
            ->values();

        $this->persistArraySamples($holding, $latestRawDate, $latestDatePrices);
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
        if (is_string($datetime) && trim($datetime) !== '') {
            return Carbon::parse($datetime, 'UTC');
        }

        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestamp((int) $timestamp, 'UTC');
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
}
