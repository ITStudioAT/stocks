<?php

namespace App\Services;

use App\MarketDataType;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class StoredMarketDataRange
{
    /**
     * @return array{date_from: ?string, date_to: ?string, row_count: int}
     */
    public function forIndex(IndexWatchItem $indexWatchItem, MarketDataType $dataType): array
    {
        return match ($dataType) {
            MarketDataType::LiveData => $this->summarize(
                $indexWatchItem->realtimePrices()->getQuery()->whereNotNull('price'),
                'trading_date',
            ),
            MarketDataType::IntradayData => $this->summarize(
                $indexWatchItem->intradayCandles()->getQuery()
                    ->where('interval', '5m')
                    ->where('source_key', 'eodhd_intraday')
                    ->whereNotNull('close'),
                'trading_date',
            ),
            MarketDataType::EndOfDayData => $this->summarize(
                $indexWatchItem->prices()->getQuery()->whereNotNull('actual_price'),
                'trading_date',
            ),
        };
    }

    /**
     * @return array{date_from: ?string, date_to: ?string, row_count: int}
     */
    public function forStock(StockHolding $holding, MarketDataType $dataType): array
    {
        return match ($dataType) {
            MarketDataType::LiveData => $this->summarize(
                $holding->realtimePrices()->getQuery()
                    ->whereNotNull('as_of')
                    ->whereNotNull('price'),
                'as_of',
                true,
            ),
            MarketDataType::IntradayData => $this->summarize(
                $holding->intradayCandles()->getQuery()
                    ->where('interval', '5m')
                    ->where('source_key', 'eodhd_intraday')
                    ->whereNotNull('close'),
                'trading_date',
            ),
            MarketDataType::EndOfDayData => $this->summarize(
                $holding->dailyPrices()->getQuery()->whereNotNull('close'),
                'trading_date',
            ),
        };
    }

    /**
     * @return array{date_from: ?string, date_to: ?string, row_count: int}
     */
    private function summarize(Builder $prices, string $dateColumn, bool $storedAsUtcTimestamp = false): array
    {
        $range = $prices
            ->toBase()
            ->selectRaw("MIN({$dateColumn}) AS date_from")
            ->selectRaw("MAX({$dateColumn}) AS date_to")
            ->selectRaw('COUNT(*) AS row_count')
            ->first();

        return [
            'date_from' => $this->storedDate($range?->date_from, $storedAsUtcTimestamp),
            'date_to' => $this->storedDate($range?->date_to, $storedAsUtcTimestamp),
            'row_count' => (int) ($range?->row_count ?? 0),
        ];
    }

    private function storedDate(mixed $value, bool $storedAsUtcTimestamp): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = Carbon::parse((string) $value, $storedAsUtcTimestamp ? 'UTC' : config('app.timezone'));

        if ($storedAsUtcTimestamp) {
            $date->setTimezone(config('app.timezone'));
        }

        return $date->toDateString();
    }
}
