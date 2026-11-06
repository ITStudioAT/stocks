<?php

namespace App\Services;

use App\Models\MarketPrice;
use App\Models\StockHolding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class MarketPriceLegacyImporter
{
    public const Tables = ['stock_prices', 'stock_realtime_prices', 'stock_holding_intraday_candles', 'stock_holding_daily_prices', 'stock_holding_intraday_prices'];

    public function __construct(private MarketIdentityRegistry $identities, private MarketPriceStore $store) {}

    /** @var Collection<int, StockHolding> */
    private Collection $holdings;

    /** @return array<string, array<string, int>> */
    public function run(int $chunk = 300): array
    {
        $this->holdings = StockHolding::query()->get()->keyBy('id');
        $listings = [];
        foreach ($this->holdings as $holding) {
            $listings[$holding->id] = $this->identities->listing($holding);
        }
        $report = [];
        foreach (self::Tables as $table) {
            $counts = ['checked' => 0, 'imported' => 0, 'quarantined' => 0, 'unchanged' => 0, 'unresolved' => 0];
            DB::table($table)->orderBy('id')->chunkById($chunk, function ($rows) use ($table, $listings, &$counts): void {
                $links = DB::table('market_price_legacy_links')->where('legacy_table', $table)->whereIn('legacy_id', $rows->pluck('id'))
                    ->get(['legacy_id', 'content_hash', 'status', 'created_at'])->keyBy('legacy_id');
                $prepared = [];
                $pending = [];
                foreach ($rows as $row) {
                    $original = (array) $row;
                    $hash = hash('sha256', json_encode($original, JSON_THROW_ON_ERROR));
                    $link = $links->get($row->id);
                    $counts['checked']++;
                    if ($link?->content_hash === $hash && $link->status !== 'unresolved') {
                        $counts['unchanged']++;

                        continue;
                    }
                    $pending[$row->id] = ['legacy_table' => $table, 'legacy_id' => $row->id, 'market_price_id' => null,
                        'content_hash' => $hash, 'status' => 'unresolved', 'original_record' => json_encode($original, JSON_THROW_ON_ERROR),
                        'created_at' => $link?->created_at ?? now()->utc(), 'updated_at' => now()->utc()];
                    try {
                        $holding = $this->holding($original);
                        if ($holding !== null) {
                            $attributes = $this->attributes($holding, $original, $table);
                            $prepared[$row->id] = $this->store->prepare($listings[$holding->id]->id, $attributes);
                            $pending[$row->id]['status'] = 'pending';
                        } else {
                            $listing = $this->identities->unresolvedListing($original, $table);
                            $unresolved = (new StockHolding)->forceFill(array_intersect_key($original, array_flip(['isin', 'wkn', 'symbol', 'currency', 'exchange'])));
                            $unresolved->mic_code = $original['mic'] ?? null;
                            $attributes = $this->attributes($unresolved, $original, $table);
                            $attributes['validation_status'] = 'quarantined';
                            $attributes['validation_errors'] = ['Instrument/listing cannot be associated with a saved instrument.'];
                            $prepared[$row->id] = $this->store->prepare($listing->id, $attributes);
                        }
                    } catch (Throwable $exception) {
                        $pending[$row->id]['status'] = 'unresolved';
                        report($exception);
                    }
                }
                DB::transaction(function () use ($prepared, $pending, &$counts): void {
                    $prices = $this->store->importBatch($prepared);
                    foreach ($pending as $id => &$link) {
                        $price = $prices[$id] ?? null;
                        $link['market_price_id'] = $price?->id;
                        $link['status'] = $price === null || $link['status'] === 'unresolved' ? 'unresolved' : ($price->validation_status === 'valid' ? 'imported' : 'quarantined');
                        $counts[$link['status']]++;
                    }
                    unset($link);
                    foreach (array_chunk(array_values($pending), 100) as $batch) {
                        DB::table('market_price_legacy_links')->upsert($batch, ['legacy_table', 'legacy_id'],
                            ['market_price_id', 'content_hash', 'status', 'original_record', 'updated_at']);
                    }
                }, 5);
            });
            $report[$table] = $counts;
        }
        $this->mergeDerivedSnapshots();
        foreach (DB::table('market_listings')->pluck('id') as $id) {
            $this->store->selectLatest($id);
        }

        return $report;
    }

    public function mergeDerivedSnapshots(): int
    {
        $tradeTime = DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract(q.raw_payload, '$.trade_at')" : "JSON_UNQUOTE(JSON_EXTRACT(q.raw_payload, '$.trade_at'))";
        $pairs = DB::table('market_prices as q')->join('market_prices as o', function ($join): void {
            $join->on('q.market_listing_id', '=', 'o.market_listing_id')->on('q.currency', '=', 'o.currency')
                ->on('q.reported_mic', '=', 'o.reported_mic')->on('q.price', '=', 'o.price');
        })->where('q.source_key', 'xetra_posttrade')->where('o.source_key', 'xetra_posttrade')
            ->where('q.record_kind', 'quote')->where('q.price_type', 'observed_trade')->where('o.record_kind', 'observation_1s')
            ->where('o.is_current', true)->where('o.validation_status', 'valid')->whereRaw('o.source_time_raw = '.$tradeTime)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('market_price_legacy_links as links')->whereColumn('links.market_price_id', 'q.id')->where('links.legacy_table', 'stock_realtime_prices'))
            ->get(['q.id as snapshot_id', 'o.id as observation_id']);
        $merged = 0;
        foreach ($pairs as $pair) {
            DB::transaction(function () use ($pair, &$merged): void {
                $snapshot = MarketPrice::query()->find($pair->snapshot_id);
                $observation = MarketPrice::query()->find($pair->observation_id);
                if ($snapshot === null || $observation === null || ! $this->derivedSnapshotMatches($snapshot->toArray(), $observation)
                    || MarketPrice::query()->where('supersedes_id', $snapshot->id)->exists()) {
                    return;
                }
                DB::table('market_price_legacy_links')->where('market_price_id', $snapshot->id)->update(['market_price_id' => $observation->id, 'status' => 'merged']);
                DB::table('market_price_selections')->where('market_price_id', $snapshot->id)->update(['market_price_id' => $observation->id]);
                DB::table('stock_holdings')->where('latest_market_price_id', $snapshot->id)->update(['latest_market_price_id' => $observation->id]);
                $snapshot->delete();
                DB::table('market_listings')->where('id', $observation->market_listing_id)->increment('data_version');
                $merged++;
            });
        }

        return $merged;
    }

    /** @param array<string, mixed> $snapshot */
    public function derivedSnapshotMatches(array $snapshot, MarketPrice $observation): bool
    {
        if (($snapshot['record_kind'] ?? null) !== 'quote' || ($snapshot['price_type'] ?? null) !== 'observed_trade'
            || ($snapshot['source_key'] ?? null) !== 'xetra_posttrade' || $observation->source_key !== 'xetra_posttrade'
            || $observation->record_kind !== 'observation_1s' || ($snapshot['currency'] ?? null) !== $observation->currency
            || ($snapshot['reported_mic'] ?? null) !== $observation->reported_mic
            || ($snapshot['raw_payload']['trade_at'] ?? null) !== $observation->source_time_raw
            || MarketPriceStore::decimal($snapshot['price'] ?? null) !== MarketPriceStore::decimal($observation->price)) {
            return false;
        }
        foreach (['bid', 'ask', 'nav', 'open', 'high', 'low', 'adjusted_close', 'volume'] as $field) {
            if (($snapshot[$field] ?? null) !== null) {
                return false;
            }
        }
        foreach (['last', 'close'] as $field) {
            if (($snapshot[$field] ?? null) !== null && MarketPriceStore::decimal($snapshot[$field]) !== MarketPriceStore::decimal($observation->price)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function holding(array $row): ?StockHolding
    {
        if (isset($row['stock_holding_id'])) {
            return $this->holdings->get($row['stock_holding_id']);
        }
        $matches = $this->holdings->where('currency', $row['currency'] ?? null);
        if (! empty($row['isin'])) {
            $matches = $matches->where('isin', $row['isin']);
        } elseif (! empty($row['wkn'])) {
            $matches = $matches->where('wkn', $row['wkn']);
        } else {
            return null;
        }
        if (! empty($row['mic']) && ! in_array($row['mic'], IntradayMarketIdentity::XetraMics, true)) {
            $matches = $matches->where('mic_code', $row['mic']);
        }

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function attributes(StockHolding $holding, array $row, string $origin = 'provider'): array
    {
        $raw = $row['raw_payload'] ?? $row;
        $raw = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : $raw;
        $raw ??= [];
        if (($row['source_key'] ?? null) === 'eodhd_eod' && ($origin === 'stock_holding_daily_prices' || ($row['price_type'] ?? null) === 'historical_eod') && isset($raw['date'])) {
            $row['interval'] = '1d';
            $row['trading_date'] = $raw['date'];
            $row['price_type'] = 'ohlc';
            $row['mic'] = null;
            $row['source_record_id'] = 'day:'.$raw['date'];
            foreach (['open', 'high', 'low', 'close', 'adjusted_close', 'volume'] as $field) {
                $row[$field] = $raw[$field] ?? $row[$field] ?? null;
            }
        }
        $interval = $row['interval'] ?? ($origin === 'stock_holding_daily_prices' ? '1d' : 'point');
        $second = in_array($interval, ['trade', 'xtrade', '1s'], true);
        $daily = $interval === '1d';
        $bar = in_array($interval, ['5m', '1d'], true);
        $source = $row['source_key'] ?? 'legacy_unknown';
        $at = $row['as_of'] ?? (isset($row['timestamp']) ? Carbon::createFromTimestampUTC($row['timestamp'])->toDateTimeString() : null);
        $timezone = app(FreeTradingCalendar::class)->timezone($holding);
        $day = isset($row['trading_date']) ? substr($row['trading_date'], 0, 10) : ($at && $timezone ? Carbon::parse($at, 'UTC')->setTimezone($timezone)->toDateString() : null);
        $session = $day ? app(FreeTradingCalendar::class)->session($holding, Carbon::parse($day, $timezone ?? 'UTC')) : null;
        $effective = $daily ? ($session['close'] ?? null) : ($bar && $at ? Carbon::parse($at, 'UTC')->addMinutes(5) : $at);
        $sourceTime = $second ? ($raw['event_at'] ?? $at) : ($daily ? $day : $at);
        $data = ['source_key' => $source, 'source_record_id' => $second ? 'second:'.$at : ($row['source_record_id'] ?? null),
            'reported_mic' => $row['mic'] ?? $raw['mic'] ?? null, 'currency' => $row['currency'] ?? null,
            'record_kind' => $second ? 'observation_1s' : ($bar ? 'bar' : ($origin === 'stock_holding_intraday_prices' ? 'legacy_sample' : 'quote')),
            'price_type' => $row['price_type'] ?? ($second ? 'observed_trade' : ($bar ? 'ohlc' : 'last')),
            'interval_code' => $second ? '1s' : $interval, 'session_type' => $session ? 'regular' : 'unknown',
            'trading_date' => $day, 'observed_at' => $daily ? null : $at,
            'source_time_raw' => $sourceTime, 'time_precision' => $daily ? 'day' : ($second && isset($raw['event_at']) ? 'nanosecond' : 'second'),
            'period_start_at' => $bar ? ($daily ? ($session['open'] ?? null) : $at) : null,
            'period_end_at' => $bar ? $effective : null, 'effective_at' => $effective,
            'time_basis' => $daily ? ($effective ? 'session_close_derived' : 'unknown') : ($bar ? 'interval_end' : 'source_time'),
            'fetched_at' => $row['fetched_at'] ?? null, 'source_published_at' => $row['source_published_at'] ?? null,
            'adjustment_basis' => 'unadjusted', 'trade_count' => $raw['trade_count'] ?? null,
            'delay_seconds' => in_array($source, ['xetra_posttrade', 'yahoo_xetra_intraday'], true) ? 900 : null,
            'validation_status' => ($raw['quarantined'] ?? false) || in_array($row['validation_status'] ?? '', ['invalid', 'rejected'], true) ? 'quarantined' : 'valid',
            'provenance' => ['source_name' => $row['source_name'] ?? $source, 'source_url' => $row['source_url'] ?? null,
                'source_quality' => str_starts_with($source, 'yahoo') ? 'unofficial' : ($source === 'xetra_posttrade' ? 'exchange_delayed' : ($row['source_quality'] ?? 'unknown')), 'original' => array_diff_key($row, ['raw_payload' => true])],
            'raw_payload' => $raw];
        foreach (MarketPriceStore::Values as $field) {
            $data[$field] = $row[$field] ?? null;
        }
        if ($daily) {
            foreach (['price', 'bid', 'ask', 'last', 'nav'] as $field) {
                $data[$field] = null;
            }
        }
        if ($second) {
            $data['price'] = $row['close'] ?? $row['price'] ?? null;
            $data['close'] = null;
        }
        if (! $bar && ! $second && $data['observed_at'] === null) {
            $data['source_record_id'] = 'legacy:'.$origin.':'.($row['id'] ?? hash('sha256', json_encode($row)));
            $data['validation_status'] = 'quarantined';
        }
        if ($data['reported_mic'] !== null && IntradayMarketIdentity::isXetra($holding->toArray()) && ! in_array($data['reported_mic'], IntradayMarketIdentity::XetraMics, true)) {
            $data['validation_status'] = 'quarantined';
        }
        if (isset($row['isin']) && $row['isin'] !== $holding->isin) {
            $data['validation_status'] = 'quarantined';
        }
        if ($session !== null && ! $daily && $at !== null) {
            $data['session_type'] = Carbon::parse($at, 'UTC')->lessThan($session['open']) ? 'pre' : (Carbon::parse($at, 'UTC')->greaterThan($session['close']) ? 'post' : 'regular');
        }

        return $data;
    }
}
