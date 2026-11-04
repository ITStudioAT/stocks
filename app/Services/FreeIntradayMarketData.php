<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ValidatedQuote;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class FreeIntradayMarketData
{
    public const CandleSources = ['eodhd_intraday', 'deutsche_boerse_trade', 'xetra_posttrade', 'yahoo_intraday', 'yahoo_xetra_intraday'];

    public const Intervals = ['5m', 'trade', 'xtrade'];

    public const Notice = 'Free intraday: Xetra trades 15 min delayed; file coverage reported separately. Yahoo OHLC unofficial. No complete historical backfill or availability guarantee.';

    public function __construct(private XetraIntradayCollector $europe, private YahooFinanceMarketData $us, private StockRealtimePriceCatalog $quotes) {}

    /** @return array<string, mixed> */
    private function identity(StockHolding $holding): array
    {
        return $holding->only(['isin', 'symbol', 'name', 'exchange', 'mic_code', 'currency']);
    }

    /** @param array<string, mixed> $row */
    private function europeanSourceUrl(array $row): string
    {
        $prefix = match ($row['trade_source']) {
            'Tradegate' => 'DGAT',
            'Frankfurt' => 'DFRA',
            default => 'DETR',
        };

        return 'https://mfs.deutsche-boerse.com/api/'.$prefix.'-posttrade';
    }

    /** @return array<string, mixed> */
    private function usResult(StockHolding $holding, bool $reload = false): array
    {
        $key = 'free-intraday.us.'.hash('sha256', json_encode($this->identity($holding)));

        return $this->cached($key, 'yahoo', fn (): array => $this->us->run(collect([$this->identity($holding)]), includeSeries: true), $reload);
    }

    /** @return array<string, mixed> */
    public function yahooSupplement(StockHolding $holding, bool $reload = false): array
    {
        $identity = $this->identity($holding);
        if (! YahooFinanceMarketData::supportsXetra($identity)) {
            throw new RuntimeException('Yahoo supplementation requires a verified ISIN/Xetra/EUR mapping.');
        }

        return $this->cached('free-intraday.xetra-yahoo.'.hash('sha256', json_encode($identity)), 'yahoo',
            fn (): array => $this->us->runXetra($identity), $reload);
    }

    /** @return array<string, mixed> */
    private function europeanResult(StockHolding $holding): array
    {
        $result = $this->europe->collect();
        foreach ($result['unresolved_corrections'] ?? [] as $isin) {
            StockHoldingIntradayCandle::withoutGlobalScopes()->whereIn('source_key', ['xetra_posttrade', 'deutsche_boerse_trade'])
                ->where('stock_holding_id', $holding->id)
                ->whereIn('stock_holding_id', StockHolding::query()->select('id')->where('isin', $isin))
                ->whereIn('trading_date', array_keys($result['days']))->update(['raw_payload->quarantined' => true]);
            StockRealtimePrice::withoutGlobalScopes()->whereIn('source_key', ['xetra_posttrade', 'deutsche_boerse_trade'])
                ->where('stock_holding_id', $holding->id)
                ->whereIn('stock_holding_id', StockHolding::query()->select('id')->where('isin', $isin))
                ->where('as_of', '>=', min(array_keys($result['days'])))->update(['raw_payload->quarantined' => true]);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function coverage(StockHolding $holding): array
    {
        if (YahooFinanceMarketData::supports($this->identity($holding))) {
            $result = $this->usResult($holding);
            $row = collect($result['instruments'])->firstWhere('isin', $holding->isin);

            return ['isin' => $holding->isin, 'symbol' => $holding->symbol, 'currency' => $holding->currency,
                'status' => ($row['price'] ?? null) === null ? 'unavailable' : 'available_unverified',
                'coverage_complete' => false, 'trade_at' => $row['trade_at'] ?? null,
                'quote_age_minutes' => isset($row['trade_at']) ? (int) Carbon::parse($row['trade_at'])->diffInMinutes(now(), absolute: true) : null,
                'warnings' => $result['warnings'], 'days' => []];
        }
        $result = $this->europeanResult($holding);

        return collect($result['instruments'])->firstWhere('isin', $holding->isin)
            ?? ['isin' => $holding->isin, 'symbol' => $holding->symbol, 'status' => 'unsupported_market',
                'coverage_complete' => false, 'error' => 'No verified free source for the configured market.'];
    }

    /** @return array<string, mixed> */
    public function xetraCoverage(bool $advance = false): array
    {
        return $this->europe->collect($advance);
    }

    /** @return array<string, mixed> */
    public static function storedCoverage(?StockHolding $holding = null): array
    {
        $report = AppConfig::query()->where('key', 'free_intraday.sync.coverage.v1')->value('value') ?? [];
        $xetra = AppConfig::query()->where('key', XetraIntradayCollector::ReportKey)->value('value') ?? [];
        $rows = collect($report['coverage'] ?? [])->keyBy('isin');
        foreach ($xetra['instruments'] ?? [] as $instrument) {
            $rows->put($instrument['isin'], [...($rows->get($instrument['isin']) ?? []), ...$instrument]);
        }
        if ($holding !== null) {
            $rows = $rows->filter(fn (array $row): bool => $row['isin'] === $holding->isin);
        }
        $excluded = StockHoldingIntradayCandle::withoutGlobalScopes()
            ->whereIn('source_key', ['deutsche_boerse_trade', 'xetra_posttrade', 'yahoo_xetra_intraday'])
            ->when($holding !== null, fn ($query) => $query->where('stock_holding_id', $holding->id))
            ->whereNotIn('id', StockHoldingIntradayCandle::query()->select('id'))->count();

        return ['checked_at' => $xetra['checked_at'] ?? $report['checked_at'] ?? null, 'notice' => self::Notice,
            'pending_files' => $xetra['pending_files'] ?? null, 'days' => $xetra['days'] ?? [],
            'instruments' => $rows->values()->all(), 'excluded_legacy_rows' => $excluded,
            'terms_url' => 'https://mfs.deutsche-boerse.com/DETR-posttrade', 'licence' => 'Free for non-commercial use; no availability/completeness guarantee.'];
    }

    /** @return array<string, mixed> */
    private function cached(string $key, string $family, callable $load, bool $reload = false): array
    {
        if (Cache::has('free-intraday.cooldown.'.$family)) {
            throw new RuntimeException("{$family}: provider cooldown after rate limit/block; try later.");
        }
        if (! $reload && Cache::has($key)) {
            return Cache::get($key);
        }
        $result = Cache::lock('free-intraday.lock.'.$family, 40)->get(function () use ($key, $load, $reload): array {
            if ($reload) {
                Cache::forget($key);
            }

            return Cache::remember($key, now()->addMinutes(2), $load);
        });
        if (! is_array($result)) {
            throw new MarketDataSourceBusy("{$family}: another source request is in progress.");
        }
        if (($result['rate_limited'] ?? false) || ($result['provider_blocked'] ?? false)) {
            Cache::add('free-intraday.cooldown.'.$family, true, now()->addMinutes(5));
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function latest(StockHolding $holding): array
    {
        $result = YahooFinanceMarketData::supports($this->identity($holding)) ? $this->usResult($holding) : $this->europeanResult($holding);
        $row = collect($result['instruments'])->firstWhere('isin', $holding->isin);
        if (($row['price'] ?? null) === null && ($row['status'] ?? null) !== 'unresolved_corrections' && YahooFinanceMarketData::supportsXetra($this->identity($holding))) {
            $result = $this->yahooSupplement($holding);
            $row = collect($result['instruments'])->firstWhere('isin', $holding->isin);
            if (is_array($row)) {
                $row['trade_source'] = 'Yahoo Finance Xetra';
                $row['trade_venue'] = 'XETR';
                $row['supplemental'] = true;
            }
        }
        if (! is_array($row) || $row['price'] === null || $row['currency'] !== $holding->currency || ($row['quote_stale'] ?? false)) {
            throw new RuntimeException(implode(' ', $result['warnings']) ?: "No usable free intraday observation for {$holding->symbol}; coverage incomplete.");
        }
        $row['warnings'] = $result['warnings'];
        $row['fetched_at'] ??= $result['checked_at'] ?? now()->toIso8601String();

        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function records(StockHolding $holding, Carbon $from, Carbon $until): array
    {
        if ($from->greaterThan($until)) {
            throw new RuntimeException('Intraday start must precede end.');
        }
        $availableFrom = null;
        try {
            $records = $this->freeRecords($holding, $from, $until, $availableFrom);
        } catch (RuntimeException $exception) {
            if ($exception instanceof MarketDataSourceBusy || $exception instanceof QueryException || ! app(EodhdApiClient::class)->configured()) {
                throw $exception;
            }

            return app(EodhdIntradayFallback::class)->records($holding, $from, $until);
        }
        if ($availableFrom !== null && $from->lt($availableFrom) && app(EodhdApiClient::class)->configured()) {
            $older = app(EodhdIntradayFallback::class)->records($holding, $from, min($until, $availableFrom->copy()->subSecond()));

            return [...$older, ...$records];
        }

        return $records;
    }

    /** @return array<int, array<string, mixed>> */
    private function freeRecords(StockHolding $holding, Carbon $from, Carbon $until, ?Carbon &$availableFrom = null): array
    {
        $isUs = YahooFinanceMarketData::supports($this->identity($holding));
        if ($from->greaterThan($until) || $from->lessThan(now()->subDays($isUs ? 7 : 60))) {
            throw new RuntimeException('Requested intraday history is outside free source retention; existing data preserved, gap cannot be recovered.');
        }
        if ($isUs) {
            $row = $this->latest($holding);
            $records = $row['series'] ?? [];
            $availableFrom = $records === [] ? null : Carbon::createFromTimestampUTC(min(array_column($records, 'timestamp')))->setTimezone('America/New_York')->startOfDay()->utc();
        } else {
            $result = $this->europeanResult($holding);
            if ($result['collector_busy'] ?? false) {
                throw new MarketDataSourceBusy('Another collector is processing files; existing data preserved.');
            }
            $records = $result['series'][$holding->isin] ?? [];
            $coverage = $this->coverage($holding);
            $firstDay = min(array_keys($result['days']) ?: [now('Europe/Berlin')->toDateString()]);
            $availableFrom = Carbon::parse($firstDay, 'Europe/Berlin')->startOfDay()->utc();
            $needsHistory = $from->copy()->setTimezone('Europe/Berlin')->toDateString() < $firstDay;
            if (($coverage['status'] ?? null) !== 'unresolved_corrections'
                && YahooFinanceMarketData::supportsXetra($this->identity($holding))
                && ($needsHistory || ($records === [] && ! $coverage['coverage_complete']))) {
                $supplement = $this->yahooSupplement($holding);
                $supplementRecords = collect($supplement['instruments'])->firstWhere('isin', $holding->isin)['series'] ?? [];
                $records = [...$records, ...$supplementRecords];
                if ($supplementRecords !== []) {
                    $availableFrom = min($availableFrom, Carbon::createFromTimestampUTC(min(array_column($supplementRecords, 'timestamp')))->setTimezone('Europe/Berlin')->startOfDay()->utc());
                }
            }
        }

        $records = array_values(array_filter($records, fn (array $record): bool => $record['timestamp'] >= $from->timestamp && $record['timestamp'] <= $until->timestamp));
        if ($records === []) {
            if (! $isUs && $coverage['coverage_complete'] && ! $needsHistory
                && $until->copy()->setTimezone('Europe/Berlin')->toDateString() <= max(array_keys($result['days']))) {
                return [];
            }
            throw new RuntimeException('No observations in requested free intraday window; coverage incomplete, no prices fabricated.');
        }

        return $records;
    }

    /** @param array<int, array<string, mixed>> $records */
    public function persist(StockHolding $holding, array $records): int
    {
        return Cache::lock('free-intraday.persist.'.$holding->id, 30)->block(2,
            fn (): int => $this->persistRecords($holding, $records));
    }

    /** @param array<int, array<string, mixed>> $records */
    private function persistRecords(StockHolding $holding, array $records): int
    {
        $official = array_values(array_filter($records, fn (array $record): bool => $record['source_key'] === 'xetra_posttrade'));
        if ($official !== [] && count($official) !== count($records)) {
            return $this->persistRecords($holding, $official)
                + $this->persistRecords($holding, array_values(array_filter($records, fn (array $record): bool => $record['source_key'] !== 'xetra_posttrade')));
        }
        if ($records !== [] && collect($records)->every(fn (array $record): bool => $record['source_key'] === 'xetra_posttrade')) {
            if (! IntradayMarketIdentity::isXetra($this->identity($holding))) {
                throw new RuntimeException('Xetra observations cannot be stored for another configured market.');
            }
            $rows = [];
            $existing = StockHoldingIntradayCandle::withoutGlobalScopes()->where('stock_holding_id', $holding->id)
                ->where('interval', 'xtrade')->whereBetween('timestamp', [min(array_column($records, 'timestamp')), max(array_column($records, 'timestamp'))])
                ->get()->keyBy('timestamp');
            foreach ($records as $record) {
                if (($record['isin'] ?? null) !== $holding->isin || $record['currency'] !== $holding->currency
                    || ! in_array($record['mic'] ?? null, IntradayMarketIdentity::XetraMics, true)) {
                    throw new RuntimeException('Xetra observation identity, currency or venue mismatch.');
                }
                $previous = $existing->get($record['timestamp']);
                if ($previous !== null && (($previous->raw_payload['mic'] ?? null) !== $record['mic']
                    || ($previous->raw_payload['event_at'] ?? '') > $record['event_at'])) {
                    continue;
                }
                $at = Carbon::createFromTimestampUTC($record['timestamp']);
                $rows[] = ['stock_holding_id' => $holding->id, 'interval' => 'xtrade', 'as_of' => $at->format('Y-m-d H:i:s'),
                    'trading_date' => $at->copy()->setTimezone('Europe/Berlin')->toDateString(), 'timestamp' => $record['timestamp'],
                    'datetime' => $record['datetime'], 'open' => null, 'high' => null, 'low' => null, 'close' => $record['close'],
                    'volume' => null, 'currency' => $record['currency'], 'source_key' => 'xetra_posttrade',
                    'source_name' => $record['source_name'], 'source_url' => $record['source_url'],
                    'raw_payload' => json_encode($record, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 300) as $chunk) {
                StockHoldingIntradayCandle::query()->upsert($chunk, ['stock_holding_id', 'interval', 'as_of'],
                    ['close', 'raw_payload', 'updated_at']);
            }

            return count($rows);
        }
        $stored = 0;
        foreach ($records as $record) {
            $at = Carbon::createFromTimestampUTC($record['timestamp']);
            $timezone = YahooFinanceMarketData::supports($this->identity($holding)) ? 'America/New_York' : 'Europe/Berlin';
            $existing = StockHoldingIntradayCandle::withoutGlobalScopes()->where('stock_holding_id', $holding->id)
                ->where('interval', $record['interval'])->where('as_of', $at)->first();
            if ($existing !== null && $existing->source_key !== $record['source_key']) {
                continue;
            }
            if ($existing !== null && $record['interval'] === 'trade'
                && (($existing->raw_payload['mic'] ?? null) !== $record['mic']
                    || ($existing->raw_payload['event_at'] ?? '') > $record['event_at'])) {
                continue;
            }
            StockHoldingIntradayCandle::query()->updateOrCreate([
                'stock_holding_id' => $holding->id, 'interval' => $record['interval'], 'as_of' => $at,
            ], [
                'trading_date' => $at->copy()->setTimezone($timezone)->toDateString(),
                'timestamp' => $record['timestamp'], 'datetime' => $record['datetime'],
                'open' => $record['open'], 'high' => $record['high'], 'low' => $record['low'], 'close' => $record['close'],
                'volume' => $record['volume'], 'currency' => $record['currency'], 'source_key' => $record['source_key'],
                'source_name' => $record['source_name'], 'source_url' => $record['source_url'], 'raw_payload' => $record,
            ]);
            $stored++;
        }

        return $stored;
    }

    /** @return array<string, mixed> */
    public function syncAll(): array
    {
        return $this->syncHoldings(StockHolding::query()->orderBy('name')->get());
    }

    /** @return array<string, mixed> */
    public function syncHolding(StockHolding $holding): array
    {
        app(PreviewIsolation::class)->assertIntegrationAllowed();

        return $this->syncHoldings(collect([$holding]), reload: true);
    }

    /** @param Collection<int, StockHolding> $holdings @return array<string, mixed> */
    private function syncHoldings(Collection $holdings, bool $reload = false): array
    {
        $stored = 0;
        $updated = 0;
        $unchanged = 0;
        $failed = 0;
        $errors = [];
        $coverage = [];
        $noTrades = 0;
        $fallbackHoldings = collect();
        foreach ($holdings as $holding) {
            $hasCurrentQuote = false;
            $freeQuoteResolved = false;
            try {
                if ($reload) {
                    if (YahooFinanceMarketData::supports($this->identity($holding))) {
                        $this->usResult($holding, reload: true);
                    } else {
                        $source = $this->europe->collect(advance: true, refreshCatalog: true);
                        $instrument = collect($source['instruments'])->firstWhere('isin', $holding->isin);
                        if (($instrument['price'] ?? null) === null && ! ($instrument['coverage_complete'] ?? false)
                            && ($instrument['status'] ?? null) !== 'unresolved_corrections'
                            && YahooFinanceMarketData::supportsXetra($this->identity($holding))) {
                            $this->yahooSupplement($holding, reload: true);
                        }
                    }
                }
                $coverage[$holding->symbol] = $this->coverage($holding);
                if ($coverage[$holding->symbol]['collector_busy'] ?? false) {
                    $failed++;
                    $errors[] = $holding->symbol.': another collector is processing files; current quote preserved.';

                    continue;
                }
                if ($coverage[$holding->symbol]['status'] === 'no_trades') {
                    $noTrades++;
                    $holding->update(['price_status' => 'no_trades']);

                    continue;
                }
                $row = $this->latest($holding);
                $freeQuoteResolved = true;
                $isUs = YahooFinanceMarketData::supports($this->identity($holding));
                $localNow = now($isUs ? 'America/New_York' : 'Europe/Berlin');
                $isOpen = $localNow->isWeekday() && $localNow->format('H:i') >= ($isUs ? '09:30' : config('services.free_intraday.session_start'))
                    && $localNow->format('H:i') < ($isUs ? '16:00' : config('services.free_intraday.session_end'));
                $freshness = (! $isUs && ! $coverage[$holding->symbol]['coverage_complete']) || ($isOpen && Carbon::parse($row['trade_at'])->lessThan(now()->subHour())) ? 'stale' : 'delayed';
                $quotePayload = $row;
                unset($quotePayload['series']);
                $supplemental = $row['supplemental'] ?? false;
                $quote = new ParsedQuote(sourceKey: $isUs ? 'yahoo_intraday' : ($supplemental ? 'yahoo_xetra_intraday' : 'xetra_posttrade'),
                    sourceName: $isUs ? 'Yahoo Finance · delay unknown' : ($supplemental ? 'Yahoo Finance Xetra · 15 min delayed' : 'Deutsche Börse · Xetra · 15 min delayed'),
                    sourceUrl: $isUs || $supplemental ? 'https://finance.yahoo.com/quote/'.rawurlencode($holding->symbol.($supplemental ? '.DE' : '')).'/' : $this->europeanSourceUrl($row),
                    sourceQuality: $isUs || $supplemental ? 'unofficial' : 'exchange_delayed', venue: $row['quote_venue'] ?? $row['trade_source'],
                    mic: $row['trade_venue'], isin: $holding->isin, symbol: $holding->symbol, currency: $row['currency'],
                    price: $row['price'], last: $row['price'], priceType: $isUs || $supplemental ? 'regular_market_last' : 'observed_trade',
                    asOf: Carbon::parse($row['trade_at'])->utc(), fetchedAt: Carbon::parse($row['fetched_at'])->utc(), freshnessStatus: $freshness,
                    rawPayload: [...$quotePayload, 'coverage_complete' => $coverage[$holding->symbol]['coverage_complete'], 'notice' => self::Notice]);
                $price = $this->quotes->store($holding, new ValidatedQuote($quote, 'valid', $freshness), $holding->trading_times);
                $hasCurrentQuote = true;
                $stored += (int) $price->wasRecentlyCreated;
                $unchanged += (int) ! $price->wasRecentlyCreated;
                $validCurrentQuote = $holding->latestRealtimePrice;
                if ($validCurrentQuote === null || Carbon::parse($validCurrentQuote->getRawOriginal('as_of'), 'UTC')->lessThanOrEqualTo($quote->asOf)) {
                    $updated += StockHolding::query()->whereKey($holding->id)
                        ->where('latest_realtime_price_id', $holding->latest_realtime_price_id)
                        ->update(['latest_realtime_price_id' => $price->id, 'latest_price_type' => $quote->priceType, 'price_status' => $freshness]);
                }
                $this->persist($holding, $this->freeRecords($holding, $isUs ? now()->subDays(6) : now('Europe/Berlin')->subWeekdays(1)->startOfDay(), now()));
                if ($row['warnings'] !== [] || (! $isUs && ! $coverage[$holding->symbol]['coverage_complete'])) {
                    $failed++;
                    $errors = [...$errors, ...$row['warnings'], ...(! $isUs && ! $coverage[$holding->symbol]['coverage_complete']
                        ? [$holding->symbol.': incomplete Xetra files; inspect instrument coverage.'] : [])];
                }
            } catch (Throwable $exception) {
                if (! $freeQuoteResolved && ! ($exception instanceof QueryException) && ! ($exception instanceof MarketDataSourceBusy)
                    && ! ($exception instanceof LockTimeoutException) && app(EodhdApiClient::class)->configured()) {
                    $fallbackHoldings->push($holding);
                    $coverage[$holding->symbol] = [...($coverage[$holding->symbol] ?? $this->identity($holding)),
                        'status' => 'fallback_pending', 'coverage_complete' => false,
                        'free_source_error' => app(EodhdErrorSanitizer::class)->message($exception->getMessage(), 255)];

                    continue;
                }
                if (! $hasCurrentQuote) {
                    $holding->update(['price_status' => 'unavailable_now']);
                }
                $failed++;
                $message = $exception instanceof QueryException
                    ? 'Database write failed; collected observations retained for retry.'
                    : app(EodhdErrorSanitizer::class)->message($exception->getMessage(), 255);
                $coverage[$holding->symbol] ??= [...$this->identity($holding), 'status' => 'unavailable', 'coverage_complete' => false];
                $coverage[$holding->symbol]['sync_error'] = $message;
                $errors[] = $holding->symbol.': '.$message;
            }
        }

        if ($fallbackHoldings->isNotEmpty()) {
            $fallback = app(EodhdBatchRealtimePriceService::class)->syncHoldings($fallbackHoldings);
            $stored += $fallback['stored_count'];
            $updated += $fallback['updated_count'];
            $unchanged += $fallback['unchanged_count'];
            $failed += $fallback['failed_count'];
            $errors = [...$errors, ...$fallback['errors']];
            foreach ($fallbackHoldings as $holding) {
                $coverage[$holding->symbol]['status'] = $fallback['failed_count'] === 0 ? 'fallback' : 'fallback_incomplete';
                if ($fallback['failed_count'] === 0) {
                    $coverage[$holding->symbol]['source_markers'] = ['EODHD'];
                }
            }
        }

        $completeCount = collect($coverage)->filter(fn (array $item): bool => $item['coverage_complete'])->count();

        $result = ['message' => "{$completeCount}/{$holdings->count()} instruments have verified file coverage; {$noTrades} no trades. ".self::Notice,
            'requested_count' => $holdings->count(), 'stored_count' => $stored, 'coverage' => array_values($coverage),
            'coverage_complete_count' => $completeCount, 'no_trade_count' => $noTrades,
            'unchanged_count' => $unchanged, 'updated_count' => $updated, 'failed_count' => $failed, 'errors' => array_values(array_unique($errors))];
        if (! $reload) {
            AppConfig::query()->updateOrCreate(['key' => 'free_intraday.sync.coverage.v1'], ['value' => ['checked_at' => now()->toIso8601String(), ...$result]]);
        }

        return $result;
    }
}
