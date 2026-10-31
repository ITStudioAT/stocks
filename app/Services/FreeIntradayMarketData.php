<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ValidatedQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class FreeIntradayMarketData
{
    public const CandleSources = ['eodhd_intraday', 'deutsche_boerse_trade', 'yahoo_intraday'];

    public const Intervals = ['5m', 'trade'];

    public const Notice = 'Free intraday: Deutsche Börse trades ~15 min delayed, sparse observations with gaps; Yahoo 5m OHLC unofficial, delay unknown. No complete historical backfill.';

    public function __construct(private DeutscheBoerseDelayedData $europe, private YahooFinanceMarketData $us, private StockRealtimePriceCatalog $quotes) {}

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
    private function usResult(StockHolding $holding): array
    {
        $key = 'free-intraday.us.'.hash('sha256', json_encode($this->identity($holding)));

        return $this->cached($key, 'yahoo', fn (): array => $this->us->run(collect([$this->identity($holding)]), includeSeries: true));
    }

    /** @return array<string, mixed> */
    private function europeanResult(): array
    {
        $instruments = StockHolding::query()->orderBy('id')->limit(50)->get()
            ->map(fn (StockHolding $holding): array => $this->identity($holding))
            ->reject(fn (array $item): bool => YahooFinanceMarketData::supports($item))->values();
        $key = 'free-intraday.eu.'.hash('sha256', json_encode($instruments->all()));

        return $this->cached($key, 'deutsche-boerse', function () use ($instruments): array {
            $rows = [];
            $warnings = [];
            $exhausted = [];
            $before = null;
            $deadline = hrtime(true) / 1e9 + 25;
            foreach (range(0, 8) as $batch) {
                $source = ['DETR', 'DGAT', 'DFRA'][$batch % 3];
                if (isset($exhausted[$source]) || $instruments->isEmpty()) {
                    continue;
                }
                if (hrtime(true) / 1e9 >= $deadline) {
                    $warnings[] = 'Deutsche Börse: search deadline reached; coverage incomplete.';
                    break;
                }
                $result = $this->europe->runBatch($instruments, $batch, $before);
                $before ??= $result['search_before'];
                $warnings = [...$warnings, ...$result['warnings']];
                foreach ($result['instruments'] as $row) {
                    $row['fetched_at'] = $result['checked_at'];
                    $rows[$row['isin']] = $row;
                }
                if ($result['next_round'] === null) {
                    $exhausted[$source] = true;
                }
                $instruments = $instruments->reject(fn (array $item): bool => isset($rows[$item['isin']]) && $rows[$item['isin']]['price'] !== null)->values();
                if ($result['rate_limited'] ?? false) {
                    Cache::add('free-intraday.cooldown.deutsche-boerse', true, now()->addMinutes(5));
                    break;
                }
            }

            return ['instruments' => array_values($rows), 'warnings' => array_values(array_unique($warnings))];
        });
    }

    /** @return array<string, mixed> */
    private function cached(string $key, string $family, callable $load): array
    {
        if (Cache::has('free-intraday.cooldown.'.$family)) {
            throw new RuntimeException("{$family}: provider cooldown after rate limit/block; try later.");
        }
        if (Cache::has($key)) {
            return Cache::get($key);
        }
        $result = Cache::lock('free-intraday.lock.'.$family, 40)->get(fn (): array => Cache::remember($key, now()->addMinutes(2), $load));
        if (! is_array($result)) {
            throw new RuntimeException("{$family}: another source request is in progress.");
        }
        if (($result['rate_limited'] ?? false) || ($result['provider_blocked'] ?? false)) {
            Cache::add('free-intraday.cooldown.'.$family, true, now()->addMinutes(5));
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function latest(StockHolding $holding): array
    {
        $result = YahooFinanceMarketData::supports($this->identity($holding)) ? $this->usResult($holding) : $this->europeanResult();
        $row = collect($result['instruments'])->firstWhere('isin', $holding->isin);
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
        $isUs = YahooFinanceMarketData::supports($this->identity($holding));
        if ($from->greaterThan($until) || $from->lessThan(now()->subDays($isUs ? 7 : 2))) {
            throw new RuntimeException('Requested intraday history is outside free source retention; existing data preserved, gap cannot be recovered.');
        }
        $row = $this->latest($holding);
        if ($isUs) {
            $records = $row['series'] ?? [];
        } else {
            $timestamp = Carbon::parse($row['trade_at'])->utc()->timestamp;
            $records = [['timestamp' => $timestamp, 'datetime' => gmdate('Y-m-d H:i:s', $timestamp),
                'open' => null, 'high' => null, 'low' => null, 'close' => $row['price'], 'volume' => null,
                'interval' => 'trade', 'currency' => $row['currency'], 'data_kind' => 'observed_trade',
                'source_key' => 'deutsche_boerse_trade', 'source_name' => 'Deutsche Börse · '.$row['trade_source'].' · '.$row['trade_venue'].' · delayed sparse trades',
                'source_url' => $this->europeanSourceUrl($row), 'venue' => $row['trade_source'],
                'mic' => $row['trade_venue'], 'event_at' => $row['trade_order_at'],
                'coverage_complete' => false, 'delay_minutes' => 15, 'warnings' => $row['warnings']]];
        }

        $records = array_values(array_filter($records, fn (array $record): bool => $record['timestamp'] >= $from->timestamp && $record['timestamp'] <= $until->timestamp));
        if ($records === []) {
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
        $stored = 0;
        foreach ($records as $record) {
            $at = Carbon::createFromTimestampUTC($record['timestamp']);
            $timezone = YahooFinanceMarketData::supports($this->identity($holding)) ? 'America/New_York' : 'Europe/Berlin';
            $existing = StockHoldingIntradayCandle::query()->where('stock_holding_id', $holding->id)
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
        $holdings = StockHolding::query()->orderBy('name')->get();
        $stored = 0;
        $updated = 0;
        $unchanged = 0;
        $failed = 0;
        $errors = [];
        foreach ($holdings as $holding) {
            $hasCurrentQuote = false;
            try {
                $row = $this->latest($holding);
                $isUs = YahooFinanceMarketData::supports($this->identity($holding));
                $quotePayload = $row;
                unset($quotePayload['series']);
                $quote = new ParsedQuote(sourceKey: $isUs ? 'yahoo_intraday' : 'deutsche_boerse_trade',
                    sourceName: $isUs ? 'Yahoo Finance · delay unknown' : 'Deutsche Börse · '.$row['trade_source'].' · 15 min delayed',
                    sourceUrl: $isUs ? 'https://finance.yahoo.com/quote/'.rawurlencode($holding->symbol).'/' : $this->europeanSourceUrl($row),
                    sourceQuality: $isUs ? 'unofficial' : 'exchange_delayed', venue: $row['quote_venue'] ?? $row['trade_source'],
                    mic: $row['trade_venue'], isin: $holding->isin, symbol: $holding->symbol, currency: $row['currency'],
                    price: $row['price'], last: $row['price'], priceType: $isUs ? 'regular_market_last' : 'observed_trade',
                    asOf: Carbon::parse($row['trade_at'])->utc(), fetchedAt: Carbon::parse($row['fetched_at'])->utc(), freshnessStatus: 'delayed',
                    rawPayload: [...$quotePayload, 'coverage_complete' => false, 'notice' => self::Notice]);
                $price = $this->quotes->store($holding, new ValidatedQuote($quote, 'valid', 'delayed'), $holding->trading_times);
                $hasCurrentQuote = true;
                $stored += (int) $price->wasRecentlyCreated;
                $unchanged += (int) ! $price->wasRecentlyCreated;
                $updated += StockHolding::query()->whereKey($holding->id)
                    ->where(fn ($query) => $query->whereNull('latest_realtime_price_id')
                        ->orWhereIn('latest_realtime_price_id', StockRealtimePrice::query()->select('id')
                            ->where('stock_holding_id', $holding->id)->where('as_of', '<=', $quote->asOf)))
                    ->update(['latest_realtime_price_id' => $price->id, 'latest_price_type' => $quote->priceType, 'price_status' => 'delayed']);
                $this->persist($holding, $this->records($holding, now()->subDays($isUs ? 6 : 1), now()));
                if ($row['warnings'] !== []) {
                    $failed++;
                    $errors = [...$errors, ...$row['warnings']];
                }
            } catch (Throwable $exception) {
                if (! $hasCurrentQuote) {
                    $holding->update(['price_status' => 'unavailable_now']);
                }
                $failed++;
                $errors[] = $holding->symbol.': '.$exception->getMessage();
            }
        }

        return ['message' => self::Notice, 'requested_count' => $holdings->count(), 'stored_count' => $stored,
            'unchanged_count' => $unchanged, 'updated_count' => $updated, 'failed_count' => $failed, 'errors' => array_values(array_unique($errors))];
    }
}
