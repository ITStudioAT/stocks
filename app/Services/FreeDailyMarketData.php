<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class FreeDailyMarketData
{
    public function __construct(private MarketIdentityRegistry $identities, private MarketDataCoordinator $coordinator,
        private MarketPriceLegacyImporter $normalizer, private MarketPriceStore $store, private FreeTradingCalendar $calendar) {}

    /** @return array<string, mixed> */
    public function import(StockHolding $holding, int $days = 365): array
    {
        if (app(MarketDataMode::class)->paused()) {
            return ['status' => 'cooldown', 'stored_count' => 0];
        }
        app(PreviewIsolation::class)->assertIntegrationAllowed();
        $listing = $this->identities->listing($holding);
        $mapping = DB::table('market_source_instruments')->where('market_listing_id', $listing->id)->where('source_key', 'yahoo')
            ->whereNotNull('verified_at')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->first();
        if ($mapping === null || $listing->status !== 'verified') {
            return ['status' => 'unsupported', 'stored_count' => 0];
        }
        if (Cache::has('free-intraday.cooldown.yahoo') || ! $this->coordinator->reserve('yahoo-symbol:'.$mapping->symbol)) {
            return ['status' => 'cooldown', 'stored_count' => 0];
        }
        $from = now($listing->timezone)->startOfDay()->subDays(max(1, min(3650, $days)) - 1);
        $until = now($listing->timezone)->startOfDay()->addDay();
        $stored = 0;
        $skipped = 0;
        try {
            $response = Http::connectTimeout(4)->timeout(15)->withOptions(['allow_redirects' => false,
                'progress' => static function (float $total, float $received): void {
                    if ($received > 4194304) {
                        throw new RuntimeException('Daily response exceeds size limit.');
                    }
                }])->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($mapping->symbol),
                    ['period1' => $from->timestamp, 'period2' => $until->timestamp, 'interval' => '1d', 'includePrePost' => 'false', 'events' => 'div,splits']);
            if (in_array($response->status(), [403, 429], true)) {
                $retry = $response->header('Retry-After');
                try {
                    $seconds = ctype_digit((string) $retry) ? (int) $retry : (int) now()->diffInSeconds(Carbon::parse($retry), false);
                } catch (Throwable) {
                    $seconds = 300;
                }
                Cache::put('free-intraday.cooldown.yahoo', true, now()->addSeconds(max(300, min(86400, $seconds))));
                throw new RuntimeException('Yahoo daily rate limit/block; no paid fallback.');
            }
            if (! $response->successful() || strlen($response->body()) > 4194304) {
                throw new RuntimeException('Daily download failed or exceeded size limit.');
            }
            $chart = $response->json('chart.result.0');
            $meta = $chart['meta'] ?? [];
            $xetra = $listing->calendar_key === 'XETRA';
            if (($meta['symbol'] ?? null) !== $mapping->symbol || ($meta['currency'] ?? null) !== $listing->currency
                || ($meta['exchangeTimezoneName'] ?? null) !== $listing->timezone || ($meta['dataGranularity'] ?? null) !== '1d'
                || ($xetra && (($meta['exchangeName'] ?? null) !== 'GER' || strtoupper($meta['fullExchangeName'] ?? '') !== 'XETRA'))
                || (! $xetra && ! in_array($meta['exchangeName'] ?? '', ['PCX', 'NYQ', 'NMS', 'NGM', 'NCM', 'NAS', 'ASE', 'BTS', 'BATS'], true))
                || ! in_array($meta['instrumentType'] ?? '', ['ETF', 'EQUITY'], true)) {
                throw new RuntimeException('Daily identity/currency/venue mismatch.');
            }
            $prices = $chart['indicators']['quote'][0] ?? [];
            foreach ($chart['timestamp'] ?? [] as $index => $timestamp) {
                if (! is_int($timestamp)) {
                    $skipped++;

                    continue;
                }
                $date = Carbon::createFromTimestampUTC($timestamp)->setTimezone($listing->timezone);
                $session = $this->calendar->session($holding, $date);
                if ($date->lessThan($from) || $date->greaterThanOrEqualTo($until) || $session === null || $session['close']->copy()->addMinutes($xetra ? 20 : 30)->greaterThan(now())) {
                    $skipped++;

                    continue;
                }
                $row = ['interval' => '1d', 'trading_date' => $date->toDateString(), 'currency' => $listing->currency,
                    'source_key' => 'yahoo_daily', 'source_name' => 'Yahoo Finance daily OHLC · unofficial',
                    'source_url' => 'https://finance.yahoo.com/quote/'.rawurlencode($mapping->symbol).'/history/', 'fetched_at' => now()->utc()->toDateTimeString(),
                    'adjusted_close' => $chart['indicators']['adjclose'][0]['adjclose'][$index] ?? null];
                foreach (['open', 'high', 'low', 'close'] as $field) {
                    $row[$field] = $prices[$field][$index] ?? null;
                    if (! is_numeric($row[$field]) || (float) $row[$field] <= 0) {
                        $skipped++;

                        continue 2;
                    }
                    $row[$field] = MarketPriceStore::decimal($row[$field]);
                }
                if ($row['high'] < max($row['open'], $row['close']) || (float) $row['low'] > min((float) $row['open'], (float) $row['close'])) {
                    $skipped++;

                    continue;
                }
                $volume = $prices['volume'][$index] ?? null;
                $row['volume'] = is_numeric($volume) && $volume >= 0 ? $volume : null;
                $row['raw_payload'] = ['provider_timestamp' => $timestamp, 'meta' => $meta, 'events' => $chart['events'] ?? [], 'values' => $row];
                $attributes = $this->normalizer->attributes($holding, $row);
                $attributes['reported_mic'] = $xetra ? 'XETR' : null;
                $stored += (int) $this->store->store($listing->id, $attributes)->wasRecentlyCreated;
            }
            Cache::put('market-data.daily.checked.'.$listing->id, true, now()->addHours(6));
            if ($stored > 0) {
                $this->coordinator->prepareReads();
            }
            $result = ['status' => $skipped ? 'partial' : 'complete', 'stored_count' => $stored, 'skipped_count' => $skipped,
                'from' => $from->toDateString(), 'to' => $until->copy()->subDay()->toDateString()];
        } catch (Throwable $exception) {
            $result = ['status' => 'failed', 'stored_count' => $stored, 'error' => $exception->getMessage()];
        }
        $this->coordinator->record('yahoo_daily', $listing->id, $result['status'], $result);

        return $result;
    }
}
