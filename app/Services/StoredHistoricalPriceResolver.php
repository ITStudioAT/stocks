<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StoredHistoricalPriceResolver
{
    public function __construct(private StockPriceCatalog $catalog, private FreeTradingCalendar $calendar) {}

    /** @param Collection<int, StockHolding> $holdings */
    public function prime(Collection $holdings, Carbon $from, Carbon $until): void
    {
        if (MarketPriceStore::enabled()) {
            $history = app(MarketPriceCompatibility::class)->history($holdings, $from, $until);
            foreach ($holdings as $holding) {
                $holding->setRelation('readHistoricalMarketPrices', collect($history[$holding->market_listing_id] ?? []));
            }

            return;
        }
        $byId = $holdings->keyBy('id');
        $byInstrument = $holdings->groupBy(fn (StockHolding $holding): string => $this->catalog->instrumentKeyForHolding($holding));
        $prices = [];
        $sources = [...StockPreviousCloseResolver::FreeSources, 'eodhd_eod', 'eodhd_realtime', 'eodhd_intraday'];

        foreach ([StockPrice::class, StockRealtimePrice::class, StockHoldingIntradayCandle::class] as $modelClass) {
            $canonical = $modelClass === StockPrice::class;
            $candle = $modelClass === StockHoldingIntradayCandle::class;
            $identity = $canonical ? 'instrument_key' : 'stock_holding_id';
            $value = $candle ? 'close' : 'price';
            $query = $modelClass::query()->whereIn('source_key', $sources)
                ->where('as_of', '>=', $from->copy()->utc())->where('as_of', '<=', $until->copy()->utc())
                ->where($value, '>', 0)
                ->where(fn ($query) => $query->whereNull('raw_payload->quarantined')->orWhere('raw_payload->quarantined', false))
                ->where(function (Builder $query) use ($holdings, $identity, $canonical): void {
                    $query->whereRaw('1 = 0');
                    foreach ($holdings as $holding) {
                        $query->orWhere(fn (Builder $query) => $query
                            ->where($identity, $canonical ? $this->catalog->instrumentKeyForHolding($holding) : $holding->id)
                            ->where('currency', $holding->currency));
                    }
                });
            if (! $candle) {
                $query->where(fn ($query) => $query->whereNull('validation_status')->orWhereNotIn('validation_status', ['invalid', 'rejected']));
            }
            $columns = ['id', $identity, 'currency', 'source_key', 'source_name', 'as_of', $value];
            if ($canonical) {
                $columns = [...$columns, 'close', 'price_type'];
            }
            $order = $candle ? 'as_of DESC, id DESC' : 'as_of DESC, fetched_at DESC, id DESC';
            $bucket = $this->dayBucketExpression();
            $ranked = $query->select($columns)->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$identity}, currency, source_key, SUBSTR(as_of, 1, 10), {$bucket} ORDER BY {$order}) AS observation_rank");
            foreach ((new $modelClass)->newQueryWithoutScopes()->fromSub($ranked, 'historical_observations')->where('observation_rank', 1)->cursor() as $record) {
                $matches = $canonical ? $byInstrument->get($record->instrument_key, collect()) : collect([$byId->get($record->stock_holding_id)])->filter();
                foreach ($matches as $holding) {
                    if ($record->currency !== $holding->currency) {
                        continue;
                    }
                    $asOf = Carbon::parse($record->getRawOriginal('as_of'), 'UTC');
                    $day = $asOf->copy()->setTimezone($this->timezone($holding))->toDateString();
                    $isClose = $canonical && $record->source_key === 'eodhd_eod' && $record->price_type === 'historical_eod';
                    $price = $isClose ? $record->close : $record->{$value};
                    $this->remember($prices, $holding, $day, $price, $asOf, MarketDataProvenance::forRecord($record), $isClose);
                }
            }
        }

        $daily = StockHoldingDailyPrice::query()->whereIn('stock_holding_id', $holdings->pluck('id'))
            ->whereIn('source_key', $sources)->where('close', '>', 0)
            ->whereBetween('trading_date', [$from->toDateString(), $until->toDateString()])
            ->where(fn ($query) => $query->whereNull('raw_payload->quarantined')->orWhere('raw_payload->quarantined', false))
            ->orderBy('id');
        foreach ($daily->select(['id', 'stock_holding_id', 'trading_date', 'close', 'currency', 'source_key', 'source_name'])->cursor() as $record) {
            $holding = $byId->get($record->stock_holding_id);
            if ($holding === null || $record->currency !== $holding->currency) {
                continue;
            }
            $day = $record->trading_date->toDateString();
            $this->remember($prices, $holding, $day, $record->close, Carbon::parse($day)->endOfDay()->utc(), MarketDataProvenance::forRecord($record), true);
        }
        foreach ($holdings as $holding) {
            $holding->setRelation('readHistoricalMarketPrices', collect($prices[$holding->id] ?? []));
        }
    }

    /** @return array{price: string, source_markers: array<int, string>, as_of: string, is_close: bool}|null */
    public function at(StockHolding $holding, Carbon $cutoff): ?array
    {
        $timezone = $this->timezone($holding);
        $localDay = $cutoff->copy()->setTimezone($timezone)->startOfDay();
        $day = $this->calendar->previousDayInTimezone($timezone, $localDay->copy()->addDay());
        $price = $holding->readHistoricalMarketPrices->get(($day ?? $localDay)->toDateString());

        return $price !== null && $price['as_of'] <= $cutoff->copy()->utc()->toDateTimeString() ? $price : null;
    }

    private function timezone(StockHolding $holding): string
    {
        if (in_array($holding->mic_code, ['XNAS', 'XNYS', 'ARCX'], true) && $holding->currency === 'USD') {
            return 'America/New_York';
        }

        return $this->calendar->timezone($holding) ?? config('app.timezone');
    }

    /**
     * Keep the last observation on both sides of every possible venue midnight:
     * New York at 04/05 UTC and Berlin/Vienna at 22/23 UTC, including DST changes.
     * Other session hours can share a bucket without dropping a venue day's last price.
     */
    private function dayBucketExpression(): string
    {
        return "CASE WHEN SUBSTR(as_of, 12, 2) IN ('04', '05', '22', '23') THEN SUBSTR(as_of, 12, 2) WHEN SUBSTR(as_of, 12, 2) < '04' THEN '00' ELSE '06' END";
    }

    /** @param array<int, array<string, array<string, mixed>>> $prices @param array<int, string> $markers */
    private function remember(array &$prices, StockHolding $holding, string $day, ?string $price, Carbon $asOf, array $markers, bool $isClose): void
    {
        if (! is_numeric($price) || (float) $price <= 0) {
            return;
        }
        $candidate = ['price' => $price, 'source_markers' => $markers, 'as_of' => $asOf->toDateTimeString(), 'is_close' => $isClose];
        $existing = $prices[$holding->id][$day] ?? null;
        if ($existing === null || [$candidate['is_close'], $candidate['as_of']] >= [$existing['is_close'], $existing['as_of']]) {
            $prices[$holding->id][$day] = $candidate;
        }
    }
}
