<?php

namespace App\Services;

use App\Models\MarketPrice;
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
        private MarketPriceLegacyImporter $normalizer, private MarketPriceStore $store, private FreeTradingCalendar $calendar,
        private DashboardPerformanceSnapshots $performanceSnapshots) {}

    /** @return array{status: string, from: ?string, to: ?string, expected_count: ?int, missing_count: ?int} */
    public function coverage(StockHolding $holding): array
    {
        $listing = DB::table('market_listings')->where('id', $holding->market_listing_id)->where('status', 'verified')->first();
        $timezone = $listing?->timezone ?? $this->calendar->timezone($holding);
        if ($timezone === null) {
            return ['status' => 'unsupported', 'from' => null, 'to' => null, 'expected_count' => null, 'missing_count' => null];
        }

        [$from, $until, $expectedDays] = $this->historyWindow($holding, $timezone, null);
        $coverage = $this->countCoverage($listing?->id, $holding->currency, $from, $until, $expectedDays);
        $status = match (true) {
            $coverage['missing_count'] === 0 => 'complete',
            $listing === null || $this->mapping($listing->id) === null => 'unsupported',
            default => 'missing',
        };

        return ['status' => $status, ...$coverage];
    }

    /** @return array<string, mixed> */
    public function import(StockHolding $holding, ?int $days = null): array
    {
        if (app(MarketDataMode::class)->paused()) {
            return ['status' => 'cooldown', 'stored_count' => 0];
        }
        app(PreviewIsolation::class)->assertIntegrationAllowed();
        $listing = $this->identities->listing($holding);
        $mapping = $this->mapping($listing->id);
        if ($mapping === null || $listing->status !== 'verified') {
            return ['status' => 'unsupported', 'stored_count' => 0];
        }
        if (Cache::has('free-intraday.cooldown.yahoo') || ! $this->coordinator->reserve('yahoo-symbol:'.$mapping->symbol)) {
            return ['status' => 'cooldown', 'stored_count' => 0];
        }
        [$from, $until, $expectedDays] = $this->historyWindow($holding, $listing->timezone, $days);
        $xetra = $listing->calendar_key === 'XETRA';
        $stored = 0;
        $skipped = 0;
        $firstHistoricalChange = null;
        $today = now()->toDateString();
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
                $date = Carbon::createFromTimestampUTC($timestamp)->setTimezone($listing->timezone)->toDateString();
                if (! isset($expectedDays[$date])) {
                    $skipped++;

                    continue;
                }
                $row = ['interval' => '1d', 'trading_date' => $date, 'currency' => $listing->currency,
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
                $observation = $this->store->store($listing->id, $attributes);
                $stored += (int) $observation->wasRecentlyCreated;
                if ($observation->wasRecentlyCreated && $observation->is_current && $row['trading_date'] < $today
                    && ($firstHistoricalChange === null || $row['trading_date'] < $firstHistoricalChange)) {
                    $firstHistoricalChange = $row['trading_date'];
                }
            }
            Cache::put('market-data.daily.checked.'.$listing->id, true, now()->addHours(6));
            if ($stored > 0) {
                $this->coordinator->prepareReads();
            }
            $coverage = $this->countCoverage($listing->id, $listing->currency, $from, $until, $expectedDays);
            $result = ['status' => $skipped > 0 || $coverage['missing_count'] > 0 ? 'partial' : 'complete',
                'stored_count' => $stored, 'skipped_count' => $skipped, ...$coverage];
        } catch (Throwable $exception) {
            $result = ['status' => 'failed', 'stored_count' => $stored, 'error' => $exception->getMessage()];
        }
        if ($firstHistoricalChange !== null) {
            $this->performanceSnapshots->invalidate(from: Carbon::parse($firstHistoricalChange));
        }
        $this->coordinator->record('yahoo_daily', $listing->id, $result['status'], $result);

        return $result;
    }

    private function mapping(int $listingId): ?object
    {
        return DB::table('market_source_instruments')->where('market_listing_id', $listingId)->where('source_key', 'yahoo')
            ->whereNotNull('verified_at')->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))->first();
    }

    /** @return array{Carbon, Carbon, array<string, true>} */
    private function historyWindow(StockHolding $holding, string $timezone, ?int $days): array
    {
        $reference = now($timezone);
        $from = $reference->copy()->startOfDay();
        $from = $days === null ? $from->subYearNoOverflow() : $from->subDays(max(1, min(3650, $days)) - 1);
        $until = $reference->copy()->startOfDay()->addDay();
        $delayMinutes = $timezone === 'America/New_York' ? 30 : 20;
        $expectedDays = [];
        for ($day = $from->copy(); $day->lessThan($until); $day->addDay()) {
            $session = $this->calendar->session($holding, $day);
            if ($session !== null && $session['close']->copy()->addMinutes($delayMinutes)->lessThanOrEqualTo($reference)) {
                $expectedDays[$day->toDateString()] = true;
            }
        }

        return [$from, $until, $expectedDays];
    }

    /**
     * @param  array<string, true>  $expectedDays
     * @return array{from: string, to: string, expected_count: int, missing_count: int}
     */
    private function countCoverage(?int $listingId, ?string $currency, Carbon $from, Carbon $until, array $expectedDays): array
    {
        $to = $until->copy()->subDay()->toDateString();
        $coveredDays = $listingId === null ? [] : MarketPrice::query()->where('market_listing_id', $listingId)
            ->where('currency', $currency)->whereIn('source_key', StockPreviousCloseResolver::FreeSources)
            ->where('interval_code', '1d')->where('adjustment_basis', 'unadjusted')
            ->where('is_current', true)->where('validation_status', 'valid')->where('close', '>', 0)
            ->whereBetween('trading_date', [$from->toDateString(), $to])->pluck('trading_date')->flip()->all();

        return ['from' => $from->toDateString(), 'to' => $to,
            'expected_count' => count($expectedDays), 'missing_count' => count(array_diff_key($expectedDays, $coveredDays))];
    }
}
