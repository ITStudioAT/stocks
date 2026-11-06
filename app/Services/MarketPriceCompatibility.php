<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketPriceCompatibility
{
    /** @return array<int, array<string, array<string, mixed>>> */
    public function history(Collection $holdings, Carbon $from, Carbon $until, bool $freeOnly = false): array
    {
        $requested = array_flip($holdings->pluck('market_listing_id')->filter()->all());
        $versions = DB::table('market_listings')->where('status', 'verified')->orderBy('id')->pluck('data_version', 'id')->all();
        $cacheFrom = min($from->toDateString(), now()->startOfYear()->subDays(14)->toDateString());
        $cacheUntil = max($until->toDateString(), now()->toDateString());
        $key = 'market-data.history.v5.'.hash('sha256', json_encode([app(MarketDataMode::class)->signature(), $versions, $cacheFrom, $cacheUntil, $freeOnly]));

        $history = Cache::remember($key, 3600, function () use ($versions, $cacheFrom, $cacheUntil, $freeOnly): array {
            $ranked = DB::table('market_prices')->whereIn('market_listing_id', array_keys($versions))
                ->where('is_current', true)->where('validation_status', 'valid')->whereNotNull('effective_at')
                ->whereBetween('trading_date', [$cacheFrom, $cacheUntil])
                ->whereNotIn('price_type', ['indicative', 'nav'])
                ->when($freeOnly, fn ($q) => $q->whereIn('source_key', StockPreviousCloseResolver::FreeSources))
                ->when($freeOnly, fn ($q) => $q->join('market_listings as currency_listing', fn ($join) => $join
                    ->on('currency_listing.id', '=', 'market_prices.market_listing_id')->on('currency_listing.currency', '=', 'market_prices.currency'))
                    ->where('market_prices.adjustment_basis', 'unadjusted'))
                ->select(['market_listing_id', 'trading_date', 'price', 'close', 'source_key', 'effective_at', 'interval_code', 'market_prices.currency', 'adjustment_basis', 'observed_at', 'record_kind'])
                ->selectRaw("ROW_NUMBER() OVER (PARTITION BY market_listing_id, trading_date ORDER BY CASE WHEN interval_code = '1d' THEN 0 ELSE 1 END, CASE WHEN interval_code = '5m' THEN period_start_at ELSE effective_at END DESC, CASE WHEN record_kind = 'bar' THEN 1 ELSE 0 END, market_prices.id DESC) AS observation_rank");
            $result = [];
            foreach (DB::query()->fromSub($ranked, 'daily_market_prices')->where('observation_rank', 1)->get() as $row) {
                $value = $row->interval_code === '1d' ? $row->close : ($row->price ?? $row->close);
                if (is_numeric($value) && (float) $value > 0) {
                    $result[$row->market_listing_id][$row->trading_date] = ['price' => (string) $value,
                        'source_key' => $row->source_key, 'source_markers' => MarketDataProvenance::markers($row->source_key), 'as_of' => $row->effective_at,
                        'trading_date' => $row->trading_date, 'currency' => $row->currency, 'adjustment_basis' => $row->adjustment_basis,
                        'observed_at' => $row->observed_at, 'interval_code' => $row->interval_code, 'record_kind' => $row->record_kind,
                        'is_close' => $row->interval_code === '1d'];
                }
            }

            return $result;
        });

        return array_map(fn (array $days): array => array_filter($days, fn (string $day): bool => $day >= $from->toDateString() && $day <= $until->toDateString(), ARRAY_FILTER_USE_KEY), array_intersect_key($history, $requested));
    }

    public function apply(Builder $query, Model $model): Builder
    {
        if (! MarketPriceStore::enabled()) {
            return $query;
        }

        return $query->fromSub($this->projection($model->getTable()), $model->getTable());
    }

    public function projection(string $table): QueryBuilder
    {
        $query = DB::table('market_prices as p')->join('market_listings as l', 'l.id', '=', 'p.market_listing_id')
            ->join('market_instruments as i', 'i.id', '=', 'l.market_instrument_id')
            ->join('stock_holdings as h', 'h.market_listing_id', '=', 'l.id')
            ->where('p.is_current', true)->where('p.validation_status', 'valid')->where('l.status', 'verified')
            ->whereColumn('h.currency', 'p.currency');
        $candle = $table === 'stock_holding_intraday_candles';
        $daily = $table === 'stock_holding_daily_prices';
        if ($candle) {
            $query->whereIn('p.interval_code', ['1s', '5m']);
        } elseif ($daily) {
            $query->where('p.interval_code', '1d');
        } elseif ($table === 'stock_holding_intraday_prices') {
            $query->where('p.record_kind', 'legacy_sample');
        } elseif ($table === 'stock_prices') {
            $query->where('p.source_key', 'like', 'eodhd%')->where(fn ($q) => $q->where('p.record_kind', 'quote')->orWhere('p.interval_code', '1d'));
        } else {
            $query->where('p.interval_code', '!=', '1d')->whereNotIn('p.price_type', ['indicative', 'nav']);
            $query->where(fn ($q) => $q->where('p.record_kind', '!=', 'bar')->orWhereIn('p.id', DB::table('market_price_selections')->where('purpose', 'latest')->select('market_price_id')));
        }
        $json = DB::connection()->getDriverName() === 'sqlite';
        $name = $json ? "json_extract(p.provenance, '$.source_name')" : "JSON_UNQUOTE(JSON_EXTRACT(p.provenance, '$.source_name'))";
        $url = $json ? "json_extract(p.provenance, '$.source_url')" : "JSON_UNQUOTE(JSON_EXTRACT(p.provenance, '$.source_url'))";
        $key = $json ? "'isin:' || i.isin" : "CONCAT('isin:', i.isin)";
        $timestamp = $json ? "CAST(strftime('%s', p.observed_at) AS INTEGER)" : 'UNIX_TIMESTAMP(p.observed_at)';
        $asOf = $candle ? 'p.observed_at' : 'p.effective_at';
        $priceType = $table === 'stock_prices' ? "CASE WHEN p.interval_code = '1d' THEN 'historical_eod' ELSE p.price_type END" : 'p.price_type';

        return $query->selectRaw("p.id, h.id as stock_holding_id, $key as instrument_key, p.observation_key as quote_hash,
            p.source_key, $name as source_name, $url as source_url, 'stored' as source_quality,
            l.venue_key as venue, p.reported_mic as mic, i.isin, i.wkn, h.symbol, p.currency,
            p.bid, p.ask, p.last, p.nav, COALESCE(p.price,p.close) as price,
            p.open,p.high,p.low,COALESCE(p.close,p.price) as close,p.adjusted_close,p.volume,
            $priceType as price_type, NULL as spread_abs, NULL as spread_pct, $asOf as as_of, p.fetched_at,
            'delayed' as freshness_status, p.validation_status,p.validation_errors,p.raw_payload,
            NULL as raw_text_hash, h.trading_times, p.trading_date,
            CASE WHEN p.interval_code = '1s' THEN 'xtrade' ELSE p.interval_code END as ".DB::connection()->getQueryGrammar()->wrap('interval').",
            $timestamp as timestamp, NULL as gmtoffset, p.observed_at as datetime,
            NULL as legacy_stock_price_id, NULL as source_stock_price_id, NULL as sample_index,
            p.adjustment_basis, p.record_kind, p.interval_code, p.created_at,p.updated_at");
    }
}
