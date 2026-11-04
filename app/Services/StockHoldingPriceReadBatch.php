<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockHoldingPriceReadBatch
{
    public function __construct(private StockPreviousCloseResolver $previousCloseResolver) {}

    /** @param Collection<int, StockHolding> $holdings */
    public function load(Collection $holdings): void
    {
        if ($holdings->isEmpty()) {
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

        foreach ($holdings as $holding) {
            $row = $metadata->get($holding->id);
            $holding->setRelation('readRealtimePrices', $realtimePrices->get($holding->id, collect()));
            $holding->setRelation('readIntradayCandles', $candles->get($holding->id, collect()));
            $holding->setRelation('readLatestIntradayDate', $row->read_intraday_date);
            $holding->setRelation('readLatestCandle', $holding->readIntradayCandles->firstWhere('id', $row->read_latest_candle_id));
            $holding->setRelation('readPreviousCandleId', $row->read_previous_candle_id);

        }
    }
}
