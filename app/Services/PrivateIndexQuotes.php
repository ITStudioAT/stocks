<?php

namespace App\Services;

use App\Jobs\RefreshPrivateIndexQuotes;
use App\Models\AppConfig;
use App\Models\IndexWatchItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PrivateIndexQuotes
{
    private const Prefix = 'private_index.yahoo.';

    private const RefreshState = 'private-index-quotes.operation';

    private const Mappings = [
        'ATX' => ['^ATX', 'INDX', 'EUR', 'INDEX', 'AT0000999982', 'VIE'],
        'GDAXI' => ['^GDAXI', 'INDX', 'EUR', 'INDEX', 'DE0008469008', 'GER'],
        'SSMI' => ['^SSMI', 'INDX', 'CHF', 'INDEX', 'CH0009980894', 'EBS'],
        'DJI' => ['^DJI', 'INDX', 'USD', 'INDEX', null, 'DJI'],
        'NDX' => ['^NDX', 'INDX', 'USD', 'INDEX', 'US6311011026', 'NIM'],
        'OEX' => ['^OEX', 'INDX', 'USD', 'INDEX', null, 'WCB'],
        '000001' => ['000001.SS', 'SHG', 'CNY', 'INDEX', null, 'SHH'],
        'KS11' => ['^KS11', 'INDX', 'KRW', 'INDEX', null, 'KSC'],
        'N225' => ['^N225', 'INDX', 'JPY', 'INDEX', 'XC0009692440', 'OSA'],
        'ATG' => ['GD.AT', 'INDX', 'EUR', 'INDEX', null, 'ATH'],
        'BBVAI' => ['BBVAI.MC', 'MC', 'EUR', 'ETF', 'ES0105336038', 'MCE'],
    ];

    public function __construct(private IndexMarketHours $marketHours) {}

    public function withRefreshLock(callable $operation): mixed
    {
        return Cache::lock(self::RefreshState.'.lock', 10)->block(5, $operation);
    }

    public function refreshStatus(): string
    {
        return Cache::get(self::RefreshState)['status'] ?? 'idle';
    }

    public function queueRefresh(bool $force = false): string
    {
        return $this->withRefreshLock(function () use ($force): string {
            $status = $this->refreshStatus();
            if ($status !== 'idle') {
                return $status;
            }

            $id = (string) Str::uuid();
            Cache::put(self::RefreshState, ['id' => $id, 'status' => 'queued'], now()->addDay());
            try {
                RefreshPrivateIndexQuotes::dispatch($force, $id);
            } catch (Throwable $exception) {
                Cache::forget(self::RefreshState);
                throw $exception;
            }

            return 'queued';
        });
    }

    public function beginRefresh(?string $id): ?string
    {
        return $this->withRefreshLock(function () use ($id): ?string {
            $operation = Cache::get(self::RefreshState);
            if ($id === null && $operation !== null) {
                return null;
            }
            if ($id !== null && (($operation['id'] ?? null) !== $id || $operation['status'] !== 'queued')) {
                return null;
            }

            $id ??= (string) Str::uuid();
            Cache::put(self::RefreshState, ['id' => $id, 'status' => 'running'], now()->addMinutes(5));

            return $id;
        });
    }

    public function finishRefresh(string $id): void
    {
        $this->withRefreshLock(function () use ($id): void {
            if ((Cache::get(self::RefreshState)['id'] ?? null) === $id) {
                Cache::forget(self::RefreshState);
            }
        });
    }

    public function refresh(bool $force = false): void
    {
        app(PreviewIsolation::class)->assertIntegrationAllowed();
        if (app(MarketDataMode::class)->paused()) {
            return;
        }
        foreach (IndexWatchItem::query()->orderBy('id')->cursor() as $item) {
            $mapping = $this->mapping($item);
            if ($mapping === null || Cache::has(YahooFinanceMarketData::CooldownKey)) {
                continue;
            }
            $record = AppConfig::query()->where('key', self::Prefix.$item->id)->first();
            if (! $force && ! $this->due($item, $record?->value)) {
                continue;
            }
            try {
                $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])
                    ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($mapping[0]), ['interval' => '1d', 'range' => '5d']);
                if (in_array($response->status(), [403, 429], true)) {
                    YahooFinanceMarketData::pauseAfter($response->header('Retry-After'), minimumSeconds: 1800);
                    break;
                }
                if (! $response->successful()) {
                    continue;
                }
                if (strlen($response->body()) > 524288) {
                    continue;
                }
                $result = $response->json('chart.result.0');
                $quote = is_array($result) ? $this->quote($item, $mapping, $result) : null;
                if ($quote !== null) {
                    $oldQuote = $record === null ? null : $this->forItem($item, [self::Prefix.$item->id => $record]);
                    if ($oldQuote !== null && Carbon::parse($quote['as_of'])->lt(Carbon::parse($oldQuote['as_of']))) {
                        $oldQuote['fetched_at'] = $quote['fetched_at'];
                        $quote = $oldQuote;
                    }
                    AppConfig::query()->updateOrCreate(['key' => self::Prefix.$item->id], ['value' => $quote]);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function forItems(iterable $items): array
    {
        $keys = collect($items)->map(fn (IndexWatchItem $item): string => self::Prefix.$item->id)->all();

        return AppConfig::query()->whereIn('key', $keys)->get()->keyBy('key')->all();
    }

    public function forItem(IndexWatchItem $item, array $records): ?array
    {
        $quote = ($records[self::Prefix.$item->id] ?? null)?->value;
        $mapping = $this->mapping($item);
        if ($mapping === null || ! is_array($quote) || ! $this->sameIdentity($quote['identity'] ?? null, $this->identity($item, $mapping))) {
            return null;
        }
        if (! isset($quote['as_of'], $quote['price']) || ! is_numeric($quote['price']) || (float) $quote['price'] <= 0) {
            return null;
        }
        try {
            Carbon::parse($quote['as_of']);
        } catch (Throwable) {
            return null;
        }

        return $quote;
    }

    private function mapping(IndexWatchItem $item): ?array
    {
        $mapping = self::Mappings[strtoupper((string) $item->symbol)] ?? null;
        if ($mapping === null || strtoupper((string) $item->exchange) !== $mapping[1]
            || strtoupper((string) $item->currency) !== $mapping[2]
            || strtoupper((string) $item->instrument_type) !== $mapping[3]
            || ($mapping[4] !== null && strtoupper((string) $item->isin) !== $mapping[4])) {
            return null;
        }

        return $mapping;
    }

    private function identity(IndexWatchItem $item, array $mapping): array
    {
        return ['symbol' => $item->symbol, 'exchange' => $item->exchange, 'currency' => $item->currency, 'type' => $item->instrument_type, 'isin' => $item->isin, 'provider_symbol' => $mapping[0]];
    }

    private function sameIdentity(mixed $actual, array $expected): bool
    {
        if (! is_array($actual)) {
            return false;
        }
        ksort($actual);
        ksort($expected);

        return $actual === $expected;
    }

    private function due(IndexWatchItem $item, ?array $saved): bool
    {
        if (! isset($saved['fetched_at']) || ! $this->sameIdentity($saved['identity'] ?? null, $this->identity($item, $this->mapping($item)))) {
            return true;
        }
        $profile = $this->marketHours->profile($item);
        $time = $profile ? now($profile['timezone']) : null;
        $open = $time && $time->isWeekday()
            && $time->between($time->copy()->setTimeFromTimeString($profile['open'])->subMinutes(15), $time->copy()->setTimeFromTimeString($profile['close'])->addHour());

        try {
            return Carbon::parse($saved['fetched_at'])->lte(now()->subMinutes($open ? 15 : 24 * 60));
        } catch (Throwable) {
            return true;
        }
    }

    private function quote(IndexWatchItem $item, array $mapping, array $result): ?array
    {
        $meta = $result['meta'] ?? [];
        $price = $meta['regularMarketPrice'] ?? null;
        $timestamp = $meta['regularMarketTime'] ?? null;
        $timezone = $meta['exchangeTimezoneName'] ?? null;
        if (($meta['symbol'] ?? null) !== $mapping[0] || ($meta['currency'] ?? null) !== $mapping[2]
            || ($meta['instrumentType'] ?? null) !== $mapping[3] || ($meta['exchangeName'] ?? null) !== $mapping[5]
            || ! is_numeric($price) || (float) $price <= 0 || ! is_numeric($timestamp) || ! is_string($timezone)
            || (int) $timestamp > now()->addMinutes(5)->timestamp || (int) $timestamp <= 0) {
            return null;
        }
        try {
            $quoteDate = Carbon::createFromTimestampUTC((int) $timestamp)->setTimezone($timezone)->toDateString();
        } catch (Throwable) {
            return null;
        }
        $previous = null;
        $previousDate = null;
        foreach (($result['timestamp'] ?? []) as $index => $barTimestamp) {
            $close = $result['indicators']['quote'][0]['close'][$index] ?? null;
            if (! is_numeric($barTimestamp) || ! is_numeric($close) || (float) $close <= 0) {
                continue;
            }
            $barDate = Carbon::createFromTimestampUTC((int) $barTimestamp)->setTimezone($timezone)->toDateString();
            if ($barDate < $quoteDate && ($previousDate === null || $barDate > $previousDate)) {
                $previous = (float) $close;
                $previousDate = $barDate;
            }
        }

        return [
            'identity' => $this->identity($item, $mapping),
            'price' => number_format((float) $price, 8, '.', ''),
            'previous_close' => $previous === null ? null : number_format($previous, 8, '.', ''),
            'change_percent' => $previous === null ? null : number_format(((float) $price - $previous) / $previous * 100, 6, '.', ''),
            'as_of' => Carbon::createFromTimestampUTC((int) $timestamp)->toIso8601String(),
            'fetched_at' => now()->toIso8601String(),
        ];
    }
}
