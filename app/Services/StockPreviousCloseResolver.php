<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockPreviousCloseResolver
{
    private const Timezone = 'Europe/Vienna';

    public function __construct(
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

    /**
     * @return array{
     *     previous_close: ?string,
     *     previous_close_date: ?string,
     *     earlier_close: ?string,
     *     earlier_close_date: ?string,
     * }
     */
    public function resolve(StockHolding $holding, Carbon|string|null $currentTradingDate): array
    {
        $cutoff = $this->cutoff($currentTradingDate);
        $officialCloses = $this->officialCloses($holding, $cutoff);
        $previousClose = $officialCloses->get(0);
        $earlierClose = $officialCloses->get(1);

        if ($previousClose instanceof StockPrice) {
            return [
                'previous_close' => $previousClose->price,
                'previous_close_date' => $this->stockPriceDate($previousClose),
                'earlier_close' => $earlierClose instanceof StockPrice ? $earlierClose->price : null,
                'earlier_close_date' => $earlierClose instanceof StockPrice ? $this->stockPriceDate($earlierClose) : null,
            ];
        }

        $fallback = $this->fallbackClose($holding, $cutoff);

        return [
            'previous_close' => $fallback['price'],
            'previous_close_date' => $fallback['date'],
            'earlier_close' => null,
            'earlier_close_date' => null,
        ];
    }

    /**
     * @return Collection<int, StockPrice>
     */
    private function officialCloses(StockHolding $holding, ?Carbon $cutoff): Collection
    {
        return $this->stockPriceCatalog->pricesForHolding($holding)
            ->where('source_key', 'eodhd_eod')
            ->where('price_type', 'historical_eod')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->when($cutoff !== null, fn ($query) => $query->where('as_of', '<', $cutoff->copy()->utc()))
            ->orderByDesc('as_of')
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->get(['id', 'price', 'as_of', 'fetched_at'])
            ->unique(fn (StockPrice $stockPrice): string => $this->stockPriceDate($stockPrice) ?? "row-{$stockPrice->id}")
            ->take(2)
            ->values();
    }

    /**
     * @return array{price: ?string, date: ?string}
     */
    private function fallbackClose(StockHolding $holding, ?Carbon $cutoff): array
    {
        $dailyPrice = StockHoldingDailyPrice::query()
            ->where('stock_holding_id', $holding->id)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('close')
                    ->orWhereNotNull('adjusted_close');
            })
            ->when($cutoff !== null, fn ($query) => $query->where('trading_date', '<', $cutoff->toDateString()))
            ->orderByDesc('trading_date')
            ->orderByDesc('id')
            ->first(['id', 'trading_date', 'close', 'adjusted_close']);

        if ($cutoff === null) {
            return [
                'price' => $dailyPrice?->close ?? $dailyPrice?->adjusted_close,
                'date' => $dailyPrice?->trading_date?->toDateString(),
            ];
        }

        $storedPrice = $this->stockPriceCatalog->pricesForHolding($holding)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '<', $cutoff->copy()->utc())
            ->orderByDesc('as_of')
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first(['id', 'price', 'as_of']);
        $realtimePrice = StockRealtimePrice::query()
            ->where('stock_holding_id', $holding->id)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '<', $cutoff->copy()->utc())
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->first(['id', 'price', 'as_of']);
        $realtimeDate = $realtimePrice === null ? null : $this->storedDate($realtimePrice, 'as_of');
        $storedPriceDate = $storedPrice === null ? null : $this->storedDate($storedPrice, 'as_of');
        $dailyDate = $dailyPrice?->trading_date?->copy()->startOfDay();

        if ($storedPrice?->price !== null && $storedPriceDate !== null && ($dailyDate === null || $storedPriceDate->gte($dailyDate))) {
            $dailyPrice = null;
            $dailyDate = $storedPriceDate;
        }

        if ($realtimePrice?->price !== null && $realtimeDate !== null && ($dailyDate === null || $realtimeDate->gte($dailyDate))) {
            return [
                'price' => $realtimePrice->price,
                'date' => $realtimeDate->toDateString(),
            ];
        }

        if ($storedPrice?->price !== null && $storedPriceDate !== null && $storedPriceDate->equalTo($dailyDate)) {
            return [
                'price' => $storedPrice->price,
                'date' => $storedPriceDate->toDateString(),
            ];
        }

        return [
            'price' => $dailyPrice?->close ?? $dailyPrice?->adjusted_close,
            'date' => $dailyPrice?->trading_date?->toDateString(),
        ];
    }

    private function cutoff(Carbon|string|null $currentTradingDate): ?Carbon
    {
        if ($currentTradingDate === null) {
            return null;
        }

        return Carbon::parse($currentTradingDate, self::Timezone)->startOfDay();
    }

    private function stockPriceDate(StockPrice $stockPrice): ?string
    {
        return $this->storedDate($stockPrice, 'as_of')?->toDateString();
    }

    private function storedDate(StockPrice|StockRealtimePrice $stockPrice, string $column): ?Carbon
    {
        $value = $stockPrice->getRawOriginal($column);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')->setTimezone(self::Timezone)->startOfDay();
    }
}
