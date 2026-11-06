<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockHoldingPriceReadBatch
{
    public function __construct(private StockPreviousCloseResolver $previousCloseResolver) {}

    /** @param Collection<int, StockHolding> $holdings */
    public function load(Collection $holdings): void
    {
        if ($holdings->isEmpty()) {
            return;
        }
        if (MarketPriceStore::enabled()) {
            $this->loadCentral($holdings);

            return;
        }

        $metadata = StockHolding::query()->whereKey($holdings->modelKeys())->select('id')
            ->selectSub(StockRealtimePrice::query()->whereIn('source_key', StockPreviousCloseResolver::FreeSources)->select('as_of')
                ->whereColumn('stock_holding_id', 'stock_holdings.id')->whereNotNull('price')->whereNotNull('as_of')
                ->orderByDesc('as_of')->orderByDesc('id')->limit(1), 'read_realtime_as_of')
            ->selectSub(StockHoldingIntradayCandle::query()->select('trading_date')
                ->whereColumn('stock_holding_id', 'stock_holdings.id')
                ->whereIn('interval', FreeIntradayMarketData::Intervals)->whereIn('source_key', StockPreviousCloseResolver::FreeSources)->whereNotNull('close')
                ->orderByDesc('trading_date')->limit(1), 'read_intraday_date')
            ->selectSub(StockHoldingIntradayCandle::query()->select('id')
                ->whereColumn('stock_holding_id', 'stock_holdings.id')->whereIn('source_key', StockPreviousCloseResolver::FreeSources)->whereNotNull('close')->whereNotNull('as_of')
                ->orderByDesc('as_of')->orderByDesc('id')->limit(1), 'read_latest_candle_id')
            ->selectSub(StockHoldingIntradayCandle::query()->select('id')
                ->whereColumn('stock_holding_id', 'stock_holdings.id')->whereIn('interval', FreeIntradayMarketData::Intervals)
                ->whereIn('source_key', StockPreviousCloseResolver::FreeSources)->whereNotNull('close')
                ->orderByDesc('as_of')->orderByDesc('id')->offset(1)->limit(1), 'read_previous_candle_id')
            ->get()->keyBy('id');

        $realtimePrices = StockRealtimePrice::query()->whereIn('source_key', StockPreviousCloseResolver::FreeSources)->whereNotNull('price')->whereNotNull('as_of')
            ->where(function ($query) use ($metadata): void {
                $query->whereRaw('1 = 0');
                foreach ($metadata as $row) {
                    if ($row->read_realtime_as_of === null) {
                        continue;
                    }
                    $date = Carbon::parse($row->read_realtime_as_of, 'UTC')->setTimezone(config('app.timezone'))->startOfDay();
                    $from = min($date->copy()->utc()->toDateTimeString(), $date->toDateString().' 00:00:00');
                    $until = max($date->copy()->addDay()->utc()->toDateTimeString(), $date->copy()->addDay()->toDateString().' 00:00:00');
                    $query->orWhere(fn ($prices) => $prices->where('stock_holding_id', $row->id)
                        ->where('as_of', '>=', $from)->where('as_of', '<', $until));
                    $query->orWhere('id', StockRealtimePrice::query()->whereIn('source_key', StockPreviousCloseResolver::FreeSources)->select('id')
                        ->where('stock_holding_id', $row->id)->whereNotNull('price')->whereNotNull('as_of')
                        ->where('as_of', '<', $from)->orderByDesc('as_of')->orderByDesc('id')->limit(1));
                }
            })->orderBy('as_of')->orderBy('id')
            ->get(['id', 'stock_holding_id', 'price', 'currency', 'as_of', 'fetched_at', 'source_key', 'source_name', 'source_url', 'venue', 'price_type', 'spread_pct', 'validation_errors', 'trading_times'])
            ->groupBy('stock_holding_id');

        $candles = StockHoldingIntradayCandle::query()->where(function ($query) use ($metadata): void {
            $query->whereIn('id', $metadata->pluck('read_latest_candle_id')->filter());
            $query->orWhereIn('id', $metadata->pluck('read_previous_candle_id')->filter());
            foreach ($metadata as $row) {
                if ($row->read_intraday_date !== null) {
                    $query->orWhere(fn ($prices) => $prices->where('stock_holding_id', $row->id)
                        ->where('trading_date', '>=', Carbon::parse($row->read_intraday_date)->toDateString())
                        ->where('trading_date', '<', Carbon::parse($row->read_intraday_date)->addDay()->toDateString())
                        ->whereIn('interval', FreeIntradayMarketData::Intervals)->whereIn('source_key', StockPreviousCloseResolver::FreeSources)->whereNotNull('close'));
                }
            }
        })->orderBy('as_of')->orderBy('id')
            ->get(['id', 'stock_holding_id', 'trading_date', 'interval', 'close', 'currency', 'as_of', 'timestamp', 'source_key', 'source_name', 'source_url'])
            ->groupBy('stock_holding_id');

        $this->previousCloseResolver->prime($holdings);

        $selections = MarketPriceStore::enabled()
            ? DB::table('market_price_selections as s')->leftJoin('market_prices as p', 'p.id', '=', 's.market_price_id')
                ->where('s.purpose', 'latest')->whereIn('s.market_listing_id', $holdings->pluck('market_listing_id'))
                ->get(['s.market_listing_id', 's.checked_at', 'p.time_basis', 'p.interval_code', 'p.record_kind', 'p.delay_seconds'])->keyBy('market_listing_id')
            : collect();

        foreach ($holdings as $holding) {
            $holding->setRelation('readMarketSelection', $selections->get($holding->market_listing_id));
            $row = $metadata->get($holding->id);
            $holding->setRelation('readRealtimePrices', $realtimePrices->get($holding->id, collect()));
            $holding->setRelation('readIntradayCandles', $candles->get($holding->id, collect()));
            $holding->setRelation('readLatestIntradayDate', $row->read_intraday_date);
            $holding->setRelation('readLatestCandle', $holding->readIntradayCandles->firstWhere('id', $row->read_latest_candle_id));
            $holding->setRelation('readPreviousCandleId', $row->read_previous_candle_id);

        }
    }

    /** @param Collection<int, StockHolding> $holdings */
    private function loadCentral(Collection $holdings): void
    {
        $ids = $holdings->pluck('market_listing_id')->filter();
        $selections = DB::table('market_price_selections as s')->leftJoin('market_prices as p', 'p.id', '=', 's.market_price_id')
            ->where('s.purpose', 'latest')->whereIn('s.market_listing_id', $ids)
            ->get(['s.market_listing_id', 's.market_price_id', 's.checked_at', 'p.time_basis', 'p.interval_code', 'p.record_kind', 'p.delay_seconds'])->keyBy('market_listing_id');
        $base = DB::table('market_prices')->whereIn('market_listing_id', $ids)->where('is_current', true)->where('validation_status', 'valid')
            ->whereIn('source_key', StockPreviousCloseResolver::FreeSources)->where('interval_code', '!=', '1d')->where('effective_at', '<=', now()->utc());
        $latest = (clone $base)->selectRaw('market_listing_id, MAX(trading_date) as trading_date')->groupBy('market_listing_id')->get()->keyBy('market_listing_id');
        $from = $latest->pluck('trading_date')->filter()->min();
        $json = DB::connection()->getDriverName() === 'sqlite';
        $sourceName = $json ? "json_extract(provenance, '$.source_name')" : "JSON_UNQUOTE(JSON_EXTRACT(provenance, '$.source_name'))";
        $sourceUrl = $json ? "json_extract(provenance, '$.source_url')" : "JSON_UNQUOTE(JSON_EXTRACT(provenance, '$.source_url'))";
        $rows = $from === null ? collect() : (clone $base)->where('trading_date', '>=', Carbon::parse($from)->subDays(7)->toDateString())
            ->select(['id', 'market_listing_id', 'trading_date', 'source_key', 'reported_mic', 'currency', 'record_kind', 'interval_code', 'price_type', 'price', 'close', 'observed_at', 'effective_at', 'fetched_at'])
            ->selectRaw($sourceName.' as source_name, '.$sourceUrl.' as source_url')->orderBy('effective_at')->orderBy('id')->get()->groupBy('market_listing_id');
        $this->previousCloseResolver->prime($holdings);
        foreach ($holdings as $holding) {
            $records = $rows->get($holding->market_listing_id, collect());
            $day = $latest->get($holding->market_listing_id)?->trading_date;
            $selection = $selections->get($holding->market_listing_id);
            $quoteRows = $records->filter(fn ($row) => $row->record_kind !== 'bar' || $row->id === $selection?->market_price_id);
            $todayRows = $quoteRows->where('trading_date', $day);
            $previous = $quoteRows->filter(fn ($row) => $row->trading_date < $day)->last();
            $sampleRows = $todayRows->groupBy(fn ($row) => substr($row->effective_at, 0, 14).intdiv((int) substr($row->effective_at, 14, 2), 5))
                ->flatMap(fn ($bucket) => [$bucket->first(), $bucket->last()])->concat($todayRows->take(-2))
                ->when($previous !== null, fn ($rows) => $rows->push($previous))->unique('id')->sortBy('effective_at')->values();
            $candleRows = $records->filter(fn ($row) => in_array($row->interval_code, ['1s', '5m'], true) && $row->trading_date === $day);
            $candleRows = $candleRows->isEmpty() ? collect() : collect([$candleRows->first()])->concat($candleRows->take(-2))->unique('id')->sortBy('observed_at')->values();
            $fields = fn ($row): array => ['id' => $row->id, 'stock_holding_id' => $holding->id, 'source_key' => $row->source_key,
                'source_name' => $row->source_name, 'source_url' => $row->source_url, 'currency' => $row->currency,
                'price_type' => $row->price_type, 'fetched_at' => $row->fetched_at, 'validation_status' => 'valid',
                'freshness_status' => 'delayed', 'trading_times' => $holding->trading_times, 'mic' => $row->reported_mic, 'venue' => $holding->exchange];
            $sampled = $sampleRows->map(fn ($row) => (new StockRealtimePrice)->newFromBuilder([...$fields($row),
                'price' => $row->price ?? $row->close, 'as_of' => $row->effective_at]));
            $candles = $candleRows->map(fn ($row) => (new StockHoldingIntradayCandle)->newFromBuilder([...$fields($row),
                'trading_date' => $day, 'interval' => $row->interval_code === '1s' ? 'xtrade' : '5m',
                'close' => $row->close ?? $row->price, 'as_of' => $row->observed_at, 'timestamp' => Carbon::parse($row->observed_at, 'UTC')->timestamp]));
            $holding->setRelation('readMarketSelection', $selection);
            $holding->setRelation('readRealtimePrices', $sampled);
            $holding->setRelation('readIntradayCandles', $candles);
            $holding->setRelation('readLatestIntradayDate', $candles->isEmpty() ? null : $day);
            $holding->setRelation('readLatestCandle', $candles->last());
            $holding->setRelation('readPreviousCandleId', $candles->count() > 1 ? $candles[$candles->count() - 2]->id : null);
        }
    }
}
