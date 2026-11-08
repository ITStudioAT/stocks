<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockPreviousCloseResolver
{
    public const FreeSources = ['deutsche_boerse_trade', 'xetra_posttrade', 'yahoo_intraday', 'yahoo_xetra_intraday', 'yahoo_daily'];

    public function __construct(private StockPriceCatalog $stockPriceCatalog, private FreeTradingCalendar $calendar) {}

    /** @param Collection<int, StockHolding> $holdings */
    public function prime(Collection $holdings, ?Carbon $reference = null, ?Carbon $from = null): void
    {
        if (MarketPriceStore::enabled()) {
            $from = $from ?? now()->subDays(14);
            $until = $reference ?? now();
            $history = app(MarketPriceCompatibility::class)->history($holdings, $from, $until, freeOnly: true);
            foreach ($holdings as $holding) {
                $holding->setRelation('readPreviousCloseObservations', $this->centralObservations($history[$holding->market_listing_id] ?? []));
                $holding->setRelation('readPreviousCloseWindow', ['from' => $from->toDateString(), 'until' => $until->toDateString()]);
            }

            return;
        }
        $from = ($from ?? ($reference ?? now())->copy()->subDays(14))->copy()->utc();
        $until = ($reference ?? now())->copy()->addDay()->utc();
        $prices = $this->dailyRecords(StockPrice::query()->whereIn('instrument_key', $holdings->map(fn (StockHolding $holding): string => $this->stockPriceCatalog->instrumentKeyForHolding($holding)))
            ->whereIn('source_key', self::FreeSources)->where('as_of', '>=', $from)->where('as_of', '<', $until), 'instrument_key', $holdings->mapWithKeys(fn (StockHolding $holding): array => [$this->stockPriceCatalog->instrumentKeyForHolding($holding) => $holding->currency])->all())->groupBy('instrument_key');
        $quotes = $this->dailyRecords(StockRealtimePrice::query()->whereIn('stock_holding_id', $holdings->pluck('id')->all())
            ->whereIn('source_key', self::FreeSources)->where('as_of', '>=', $from)->where('as_of', '<', $until), 'stock_holding_id', $holdings->pluck('currency', 'id')->all())->groupBy('stock_holding_id');
        $candles = $this->dailyRecords(StockHoldingIntradayCandle::query()->whereIn('stock_holding_id', $holdings->pluck('id')->all())
            ->whereIn('source_key', self::FreeSources)->where('as_of', '>=', $from)->where('as_of', '<', $until), 'stock_holding_id', $holdings->pluck('currency', 'id')->all())->groupBy('stock_holding_id');

        foreach ($holdings as $holding) {
            $observations = collect($prices->get($this->stockPriceCatalog->instrumentKeyForHolding($holding), collect())->all())
                ->concat($quotes->get($holding->id, collect()))->concat($candles->get($holding->id, collect()));
            $holding->setRelation('readPreviousCloseObservations', $observations);
        }
    }

    /**
     * Last observed free prices on the expected venue days; never official closes.
     *
     * @return array{previous_close: ?string, previous_close_date: ?string, previous_close_as_of: ?string, previous_close_source_markers: array<int, string>, earlier_close: ?string, earlier_close_date: ?string, earlier_close_source_markers: array<int, string>}
     */
    public function resolve(StockHolding $holding): array
    {
        if (MarketPriceStore::enabled()) {
            return $this->forObservation($holding, $this->latestObservation($holding));
        }
        $timezone = $this->calendar->timezone($holding);
        $reference = now($timezone ?? 'Europe/Vienna')->startOfDay();

        return $this->beforeDay($holding, $reference);
    }

    /** @return array{previous_close: ?string, previous_close_date: ?string, previous_close_as_of: ?string, previous_close_source_markers: array<int, string>, earlier_close: ?string, earlier_close_date: ?string, earlier_close_source_markers: array<int, string>} */
    public function forObservation(StockHolding $holding, StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $observation): array
    {
        if (! MarketPriceStore::enabled()) {
            return $this->resolve($holding);
        }
        $day = $this->tradingDateOf($holding, $observation);
        $timezone = $this->calendar->timezone($holding);
        $reference = $day === null || $timezone === null || $observation?->adjustment_basis !== 'unadjusted'
            ? null : Carbon::parse($day, $timezone)->startOfDay();

        return $this->beforeDay($holding, $reference);
    }

    public function tradingDateOf(StockHolding $holding, StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $observation): ?string
    {
        if ($observation === null) {
            return null;
        }
        if (MarketPriceStore::enabled() && $observation->getRawOriginal('trading_date') !== null) {
            return substr($observation->getRawOriginal('trading_date'), 0, 10);
        }
        $timezone = $this->calendar->timezone($holding);
        $asOf = $observation->getRawOriginal('as_of');

        return $timezone === null || $asOf === null ? null : Carbon::parse($asOf, 'UTC')->setTimezone($timezone)->toDateString();
    }

    /** @return array{previous_close: ?string, previous_close_date: ?string, previous_close_as_of: ?string, previous_close_source_markers: array<int, string>, earlier_close: ?string, earlier_close_date: ?string, earlier_close_source_markers: array<int, string>} */
    private function beforeDay(StockHolding $holding, ?Carbon $reference): array
    {
        $previousDay = $reference === null ? null : $this->calendar->previousDay($holding, $reference);
        $earlierDay = $previousDay === null ? null : $this->calendar->previousDay($holding, $previousDay);
        $observations = $previousDay === null ? collect() : $this->observations($holding, $earlierDay ?? $previousDay, $reference);
        $previous = $previousDay === null ? null : $this->lastObservation($holding, $observations, $previousDay);
        $earlier = $earlierDay === null ? null : $this->lastObservation($holding, $observations, $earlierDay);

        return [
            'previous_close' => $this->observationPrice($previous),
            'previous_close_date' => $previousDay?->toDateString(),
            'previous_close_as_of' => $this->observationTimestamp($previous),
            'previous_close_source_markers' => $previous === null ? [] : ['free'],
            'earlier_close' => $this->observationPrice($earlier),
            'earlier_close_date' => $earlierDay?->toDateString(),
            'earlier_close_source_markers' => $earlier === null ? [] : ['free'],
        ];
    }

    public function comparisonReference(StockHolding $holding, Carbon|string|StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $asOf, ?string $previousPrice, ?string $previousDate): ?string
    {
        $timezone = $this->calendar->timezone($holding);

        if ($timezone === null || $asOf === null || $previousPrice === null || $previousDate === null) {
            return null;
        }

        $date = $asOf instanceof StockPrice || $asOf instanceof StockRealtimePrice || $asOf instanceof StockHoldingIntradayCandle
            ? $this->tradingDateOf($holding, $asOf) : Carbon::parse($asOf, 'UTC')->setTimezone($timezone)->toDateString();

        return $date !== null && $date > $previousDate && $date <= now($timezone)->toDateString() ? $previousPrice : null;
    }

    public function latestObservation(StockHolding $holding): StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null
    {
        $observations = $holding->relationLoaded('readPreviousCloseObservations')
            ? $holding->readPreviousCloseObservations
            : $this->observations($holding, now()->subDays(14), now()->addDay());

        return $observations->filter(fn ($record): bool => is_numeric($record->price ?? $record->close) && (float) ($record->price ?? $record->close) > 0
            && $record->currency === $holding->currency && in_array($record->source_key, self::FreeSources, true)
            && ! ($record->raw_payload['quarantined'] ?? false)
            && ! in_array($record->validation_status, ['invalid', 'rejected'], true))
            ->sortByDesc(fn ($record): string => ($record->getRawOriginal('as_of') ?? '').'|'.($record->getRawOriginal('fetched_at') ?? ''))->first();
    }

    public function latestPrice(StockHolding $holding): ?string
    {
        $record = $this->latestObservation($holding);

        if ($record !== null) {
            return (string) ($record->price ?? $record->close);
        }

        return MarketDataProvenance::markers($holding->latest_price_source) === ['free'] ? $holding->latest_price : null;
    }

    public function priceAt(StockHolding $holding, Carbon $cutoff): ?string
    {
        $timezone = $this->calendar->timezone($holding);

        if ($timezone === null) {
            return null;
        }

        $day = $this->calendar->previousDay($holding, $cutoff->copy()->setTimezone($timezone)->startOfDay()->addDay());

        if ($day === null) {
            return null;
        }

        $observation = $this->lastObservation($holding, $this->observations($holding, $day, $day->copy()->addDay()), $day);

        return $this->observationPrice($observation);
    }

    /**
     * @param  array<int|string, string|null>  $currencies
     * @return Collection<int, StockPrice|StockRealtimePrice|StockHoldingIntradayCandle>
     */
    private function dailyRecords(Builder $query, string $partition, array $currencies): Collection
    {
        $query->where(function (Builder $query) use ($partition, $currencies): void {
            $query->whereRaw('1 = 0');

            foreach ($currencies as $identity => $currency) {
                $query->orWhere(fn (Builder $query) => $query->where($partition, $identity)->where('currency', $currency));
            }
        });
        $model = $query->getModel();
        $table = $model->getTable();
        $query->where(fn ($query) => $query->whereNull('raw_payload->quarantined')->orWhere('raw_payload->quarantined', false));

        if (! $model instanceof StockHoldingIntradayCandle) {
            $query->whereNotNull('price')->where('price', '>', 0)
                ->where(fn ($query) => $query->whereNull('validation_status')->orWhereNotIn('validation_status', ['invalid', 'rejected']));
        } else {
            $query->whereNotNull('close')->where('close', '>', 0);
        }

        $order = $model instanceof StockHoldingIntradayCandle ? 'as_of DESC, id DESC' : 'as_of DESC, fetched_at DESC, id DESC';
        $columns = ['id', 'source_key', 'source_name', 'source_url', 'currency', 'as_of', 'created_at', 'updated_at'];
        $columns = [...$columns, ...($model instanceof StockHoldingIntradayCandle
            ? ['stock_holding_id', 'close', 'trading_date', 'interval']
            : [$model instanceof StockPrice ? 'instrument_key' : 'stock_holding_id', 'price', 'price_type', 'fetched_at', 'validation_status', 'freshness_status'])];
        $ranked = $query->select(array_map(fn (string $column): string => "{$table}.{$column}", $columns))
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$partition}, SUBSTR(as_of, 1, 13) ORDER BY {$order}) AS observation_rank");

        return $model->newQueryWithoutScopes()->fromSub($ranked, $table)->where('observation_rank', 1)->get();
    }

    /** @return Collection<int, StockPrice|StockRealtimePrice|StockHoldingIntradayCandle> */
    private function observations(StockHolding $holding, Carbon $from, Carbon $until): Collection
    {
        if (MarketPriceStore::enabled()) {
            $window = $holding->relationLoaded('readPreviousCloseWindow') ? $holding->readPreviousCloseWindow : null;
            if ($window !== null && $from->toDateString() >= $window['from'] && $until->toDateString() <= $window['until']) {
                return $holding->readPreviousCloseObservations;
            }
            $history = app(MarketPriceCompatibility::class)->history(collect([$holding]), $from, $until, freeOnly: true);

            return $this->centralObservations($history[$holding->market_listing_id] ?? []);
        }
        if ($holding->relationLoaded('readPreviousCloseObservations')) {
            return $holding->readPreviousCloseObservations;
        }

        $from = $from->copy()->utc();
        $until = $until->copy()->utc();
        $prices = $this->stockPriceCatalog->pricesForHolding($holding)->whereIn('source_key', self::FreeSources)
            ->where('as_of', '>=', $from)->where('as_of', '<', $until)->get();
        $quotes = StockRealtimePrice::query()->where('stock_holding_id', $holding->id)->whereIn('source_key', self::FreeSources)
            ->where('as_of', '>=', $from)->where('as_of', '<', $until)->get();
        $candles = StockHoldingIntradayCandle::query()->where('stock_holding_id', $holding->id)->whereIn('source_key', self::FreeSources)
            ->where('as_of', '>=', $from)->where('as_of', '<', $until)->get();

        return collect($prices->all())->concat($quotes)->concat($candles);
    }

    /** @param Collection<int, StockPrice|StockRealtimePrice|StockHoldingIntradayCandle> $observations */
    private function lastObservation(StockHolding $holding, Collection $observations, Carbon $day): StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null
    {
        $from = $day->copy()->utc()->toDateTimeString();
        $until = $day->copy()->addDay()->utc()->toDateTimeString();
        $central = MarketPriceStore::enabled();

        return $observations->filter(function ($record) use ($holding, $from, $until, $day, $central): bool {
            $asOf = $record->getRawOriginal('as_of');
            $price = $record instanceof StockHoldingIntradayCandle ? $record->close : $record->price;

            $matchesDay = $central
                ? $record->getRawOriginal('trading_date') === $day->toDateString() && $record->adjustment_basis === 'unadjusted'
                : $asOf !== null && $asOf >= $from && $asOf < $until;

            return $matchesDay
                && is_numeric($price) && (float) $price > 0 && $record->currency === $holding->currency
                && in_array($record->source_key, self::FreeSources, true)
                && ! ($record->raw_payload['quarantined'] ?? false)
                && ! in_array($record->validation_status, ['invalid', 'rejected'], true);
        })->sortByDesc(fn ($record): string => $record->getRawOriginal('as_of').'|'.($record->getRawOriginal('fetched_at') ?? ''))->first();
    }

    private function observationPrice(StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $observation): ?string
    {
        $price = $observation instanceof StockHoldingIntradayCandle ? $observation->close : $observation?->price;

        return $price === null ? null : (string) $price;
    }

    private function observationTimestamp(StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $observation): ?string
    {
        if ($observation === null || $observation->source_key === 'yahoo_daily') {
            return null;
        }

        if (MarketPriceStore::enabled()) {
            if ($observation->record_kind === 'bar' || $observation->interval_code === '1d') {
                return null;
            }

            $timestamp = $observation->getRawOriginal('observed_at');
        } else {
            $hasSourceTime = $observation instanceof StockHoldingIntradayCandle
                ? in_array($observation->interval, ['trade', 'xtrade'], true)
                : ! in_array($observation->price_type, ['historical_eod', 'daily_close', 'ohlc'], true);
            $timestamp = $hasSourceTime ? $observation->getRawOriginal('as_of') : null;
        }

        return blank($timestamp) ? null : Carbon::parse($timestamp, 'UTC')->utc()->toIso8601String();
    }

    /** @param array<string, array<string, mixed>> $history
     * @return Collection<int, StockRealtimePrice>
     */
    private function centralObservations(array $history): Collection
    {
        return collect($history)->map(fn (array $row) => (new StockRealtimePrice)->newFromBuilder([
            'price' => $row['price'], 'as_of' => $row['as_of'], 'observed_at' => $row['observed_at'], 'source_key' => $row['source_key'], 'currency' => $row['currency'],
            'trading_date' => $row['trading_date'], 'adjustment_basis' => $row['adjustment_basis'], 'interval_code' => $row['interval_code'],
            'record_kind' => $row['record_kind'], 'validation_status' => 'valid']))->values();
    }
}
