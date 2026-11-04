<?php

namespace App\Services;

use App\MarketDataType;
use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemIntradayCandle;
use App\Models\IndexWatchItemPrice;
use App\Models\IndexWatchItemRealtimePrice;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class StoredMarketDataRange
{
    private const GlobalStatisticsCacheSeconds = 5;

    /**
     * @return array{date_from: ?string, date_to: ?string, row_count: int}
     */
    public function forAllIndices(MarketDataType $dataType): array
    {
        return match ($dataType) {
            MarketDataType::LiveData => $this->summarize(
                IndexWatchItemRealtimePrice::query()->whereNotNull('price'),
                'trading_date',
                cacheGlobally: true,
            ),
            MarketDataType::IntradayData => $this->summarize(
                IndexWatchItemIntradayCandle::query()
                    ->where('interval', '5m')
                    ->where('source_key', 'eodhd_intraday')
                    ->whereNotNull('close'),
                'trading_date',
                cacheGlobally: true,
            ),
            MarketDataType::EndOfDayData => $this->summarize(
                IndexWatchItemPrice::query()->whereNotNull('actual_price'),
                'trading_date',
                cacheGlobally: true,
            ),
        };
    }

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
                    ->whereIn('interval', FreeIntradayMarketData::Intervals)
                    ->whereIn('source_key', FreeIntradayMarketData::CandleSources)
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
    public function forAllStocks(MarketDataType $dataType): array
    {
        return match ($dataType) {
            MarketDataType::LiveData => $this->summarize(
                StockRealtimePrice::query()
                    ->whereNotNull('stock_holding_id')
                    ->whereNotNull('as_of')
                    ->whereNotNull('price'),
                'as_of',
                true,
                cacheGlobally: true,
            ),
            MarketDataType::IntradayData => $this->summarize(
                StockHoldingIntradayCandle::query()
                    ->whereIn('interval', FreeIntradayMarketData::Intervals)
                    ->whereIn('source_key', FreeIntradayMarketData::CandleSources)
                    ->whereNotNull('close'),
                'trading_date',
                cacheGlobally: true,
            ),
            MarketDataType::EndOfDayData => $this->summarize(
                StockHoldingDailyPrice::query()->whereNotNull('close'),
                'trading_date',
                cacheGlobally: true,
            ),
        };
    }

    /**
     * @return array{date_from: ?string, date_to: ?string, row_count: int}
     */
    private function summarize(Builder $prices, string $dateColumn, bool $storedAsUtcTimestamp = false, bool $cacheGlobally = false): array
    {
        if ($cacheGlobally) {
            $connection = $prices->getConnection();
            $key = 'market-data-range:v2:'.hash('sha256', serialize([
                $connection->getName(), $connection->getDatabaseName(), config('app.timezone'),
                $prices->toSql(), $prices->getBindings(), $dateColumn, $storedAsUtcTimestamp,
            ]));

            return Cache::remember($key, now()->addSeconds(self::GlobalStatisticsCacheSeconds), fn (): array => $this->summarize($prices, $dateColumn, $storedAsUtcTimestamp));
        }

        $sourcePrices = clone $prices;
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
            'source_markers' => $this->sourceMarkers($sourcePrices),
        ];
    }

    /** @return array<int, string> */
    private function sourceMarkers(Builder $prices): array
    {
        $markers = [];
        if ($prices->getModel() instanceof IndexWatchItemPrice) {
            foreach ((clone $prices)->select('raw_payload->_source as provenance_source')->distinct()->toBase()->get()->pluck('provenance_source') as $source) {
                $markers = MarketDataProvenance::merge($markers, MarketDataProvenance::markers($source));
            }
            $legacy = (clone $prices)->whereNull('raw_payload->_source')->where(function (Builder $query): void {
                $query->where(fn (Builder $query) => $query->whereNotNull('raw_payload->date')->whereNotNull('raw_payload->close')->whereJsonContainsKey('raw_payload->adjusted_close'))
                    ->orWhere(fn (Builder $query) => $query->whereNotNull('raw_payload->code')->whereNotNull('raw_payload->timestamp')->whereNotNull('raw_payload->close'));
            })->exists();

            return MarketDataProvenance::merge($markers, $legacy ? ['EODHD'] : []);
        }
        $columns = array_values(array_intersect(['source_key', 'source_name'], $prices->getModel()->getFillable()));
        if ($columns === []) {
            return [];
        }
        foreach ((clone $prices)->select($columns)->distinct()->get() as $price) {
            $markers = MarketDataProvenance::merge($markers, MarketDataProvenance::forRecord($price));
        }

        return $markers;
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
