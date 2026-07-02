<?php

namespace App\Services;

use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class IndexWatchItemPriceRefresher
{
    public const RecentPriceLimit = 30;

    public function __construct(
        private EodhdApiClient $apiClient,
        private EodhdMarketData $marketData,
    ) {}

    public function ensureRecentPrices(IndexWatchItem $item, int $limit = self::RecentPriceLimit): bool
    {
        if ($this->hasRecentPrices($item, $limit)) {
            return true;
        }

        $this->refresh($item);
        $this->storeHistoricalDailyPrices($item, $limit, fillLatest: false);
        $item->refresh();

        return $this->hasRecentPrices($item, $limit);
    }

    public function refresh(IndexWatchItem $item): bool
    {
        if (! $this->apiClient->configured()) {
            return false;
        }

        try {
            $response = $this->apiClient->get("real-time/{$this->eodhdSymbol($item)}", [
                'fmt' => 'json',
            ]);
        } catch (Throwable) {
            return false;
        }

        if ($response->failed()) {
            return false;
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['status'] ?? null) === 'error') {
            return false;
        }

        $actualPrice = $this->decimal(Arr::get($payload, 'close'));
        $startPrice = $this->decimal(Arr::get($payload, 'open'));
        $lastPrice = $this->decimal(Arr::get($payload, 'previousClose'));
        $changePercent = $this->signedDecimal(Arr::get($payload, 'change_p'));
        $asOf = $this->timestamp(Arr::get($payload, 'timestamp'), Arr::get($payload, 'datetime')) ?? now();

        if ($actualPrice === null && $lastPrice === null) {
            return false;
        }

        $tradingDate = $asOf->copy()
            ->setTimezone($this->exchangeTimezone($item))
            ->toDateString();

        $this->updateOrCreateDailyPrice($item, $tradingDate, [
            'start_price' => $startPrice,
            'actual_price' => $actualPrice,
            'last_price' => $lastPrice,
            'actual_price_as_of' => $actualPrice !== null ? $asOf : null,
            'last_price_as_of' => $lastPrice !== null ? $asOf : null,
            'raw_payload' => $payload,
        ]);

        $referencePrice = $lastPrice ?? $startPrice;

        $item->update([
            'currency' => $this->stringOrNull(Arr::get($payload, 'currency')) ?? $item->currency,
            'start_price' => $startPrice,
            'latest_price' => $actualPrice ?? $lastPrice,
            'last_price' => $lastPrice,
            'latest_price_change_pct' => $changePercent ?? $this->changePercent($actualPrice ?? $lastPrice, $referencePrice),
            'latest_price_as_of' => $asOf,
            'latest_price_source' => 'EODHD real-time',
            'trading_times' => $this->tradingTimes($item),
            'raw_payload' => $payload,
        ]);

        return true;
    }

    /**
     * @return array{requested_count: int, refreshed_count: int, failed_count: int}
     */
    public function refreshAll(): array
    {
        $requestedCount = 0;
        $refreshedCount = 0;

        foreach (IndexWatchItem::query()->orderBy('id')->cursor() as $item) {
            $requestedCount++;

            if ($this->refresh($item)) {
                $refreshedCount++;
            }
        }

        return [
            'requested_count' => $requestedCount,
            'refreshed_count' => $refreshedCount,
            'failed_count' => $requestedCount - $refreshedCount,
        ];
    }

    /**
     * @return array{requested_count: int, stored_count: int}
     */
    public function syncHistoricalDailyPricesForAll(int $limit = self::RecentPriceLimit): array
    {
        $requestedCount = 0;
        $storedCount = 0;

        foreach (IndexWatchItem::query()->orderBy('id')->cursor() as $item) {
            $requestedCount++;
            $storedCount += $this->storeHistoricalDailyPrices($item, $limit);
        }

        return [
            'requested_count' => $requestedCount,
            'stored_count' => $storedCount,
        ];
    }

    private function hasRecentPrices(IndexWatchItem $item, int $limit): bool
    {
        return $item->prices()
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('start_price')
                    ->orWhereNotNull('actual_price')
                    ->orWhereNotNull('last_price');
            })
            ->orderByDesc('trading_date')
            ->limit($limit)
            ->get(['id'])
            ->count() >= $limit;
    }

    private function storeHistoricalDailyPrices(IndexWatchItem $item, int $limit, bool $fillLatest = true): int
    {
        if (! $this->apiClient->configured()) {
            return 0;
        }

        $timezone = $this->exchangeTimezone($item);
        $until = now($timezone);
        $from = $until->copy()->subDays($limit * 3);

        try {
            $response = $this->apiClient->get("eod/{$this->eodhdSymbol($item)}", [
                'from' => $from->toDateString(),
                'to' => $until->toDateString(),
                'period' => 'd',
                'fmt' => 'json',
            ]);
        } catch (Throwable) {
            return 0;
        }

        if ($response->failed()) {
            return 0;
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['status'] ?? null) === 'error') {
            return 0;
        }

        $records = collect($payload)
            ->filter(fn (mixed $record): bool => is_array($record))
            ->map(fn (array $record): ?array => $this->historicalDailyPriceData($record, $timezone))
            ->filter()
            ->sortByDesc('trading_date')
            ->take($limit)
            ->values();

        $stored = 0;

        foreach ($records as $record) {
            $this->updateOrCreateDailyPrice($item, $record['trading_date'], [
                'start_price' => $record['start_price'],
                'actual_price' => $record['actual_price'],
                'last_price' => $record['last_price'],
                'actual_price_as_of' => $record['actual_price_as_of'],
                'last_price_as_of' => $record['last_price_as_of'],
                'raw_payload' => $record['raw_payload'],
            ]);

            $stored++;
        }

        if ($stored > 0 && $fillLatest) {
            $this->fillLatestPriceFromStoredHistory($item);
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateOrCreateDailyPrice(IndexWatchItem $item, string $tradingDate, array $values): void
    {
        $price = IndexWatchItemPrice::query()
            ->where('index_watch_item_id', $item->id)
            ->whereDate('trading_date', $tradingDate)
            ->first();

        if (! $price) {
            $price = new IndexWatchItemPrice([
                'index_watch_item_id' => $item->id,
                'trading_date' => $tradingDate,
            ]);
        }

        $price->fill($values);
        $price->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{trading_date: string, start_price: ?string, actual_price: ?string, last_price: ?string, actual_price_as_of: Carbon, last_price_as_of: Carbon, raw_payload: array<string, mixed>}|null
     */
    private function historicalDailyPriceData(array $payload, string $timezone): ?array
    {
        $tradingDate = $this->date(Arr::get($payload, 'date'), $timezone);
        $startPrice = $this->decimal(Arr::get($payload, 'open'));
        $actualPrice = $this->decimal(Arr::get($payload, 'close'));
        $lastPrice = $this->decimal(Arr::get($payload, 'adjusted_close')) ?? $actualPrice;

        if ($tradingDate === null || ($startPrice === null && $actualPrice === null && $lastPrice === null)) {
            return null;
        }

        $asOf = $tradingDate->copy()->endOfDay()->utc();

        return [
            'trading_date' => $tradingDate->toDateString(),
            'start_price' => $startPrice,
            'actual_price' => $actualPrice,
            'last_price' => $lastPrice,
            'actual_price_as_of' => $asOf,
            'last_price_as_of' => $asOf,
            'raw_payload' => $payload,
        ];
    }

    private function fillLatestPriceFromStoredHistory(IndexWatchItem $item): void
    {
        $latestPrice = $item->prices()
            ->orderByDesc('trading_date')
            ->first();

        if (! $latestPrice) {
            return;
        }

        $latestPriceAsOf = $latestPrice->actual_price_as_of ?? $latestPrice->last_price_as_of;

        if ($item->latest_price_as_of !== null && $latestPriceAsOf !== null && $item->latest_price_as_of->greaterThanOrEqualTo($latestPriceAsOf)) {
            return;
        }

        $actualPrice = $this->decimal($latestPrice->actual_price);
        $startPrice = $this->decimal($latestPrice->start_price);
        $lastPrice = $this->decimal($latestPrice->last_price);
        $previousPrice = $item->prices()
            ->whereDate('trading_date', '<', $latestPrice->trading_date)
            ->orderByDesc('trading_date')
            ->first();
        $referencePrice = $this->decimal($previousPrice?->actual_price)
            ?? $this->decimal($previousPrice?->last_price)
            ?? $lastPrice
            ?? $startPrice;

        $item->update([
            'start_price' => $startPrice,
            'latest_price' => $actualPrice ?? $lastPrice,
            'last_price' => $lastPrice,
            'latest_price_change_pct' => $this->changePercent($actualPrice ?? $lastPrice, $referencePrice),
            'latest_price_as_of' => $latestPriceAsOf,
            'latest_price_source' => 'EODHD EOD',
            'trading_times' => $this->tradingTimes($item),
        ]);
    }

    private function eodhdSymbol(IndexWatchItem $item): string
    {
        return Str::upper((string) $item->symbol).'.'.$this->exchangeCode($item);
    }

    private function exchangeCode(IndexWatchItem $item): string
    {
        $exchange = Str::upper((string) $item->exchange);

        return $exchange !== '' ? $exchange : $this->marketData->exchangeCodeForIndexWatchItem($item);
    }

    private function exchangeTimezone(IndexWatchItem $item): string
    {
        $details = $this->marketData->exchangeDetailsForCode(
            $this->marketData->exchangeCodeForIndexWatchItem($item),
        );

        return $details['timezone'] ?? config('app.timezone', 'UTC');
    }

    private function tradingTimes(IndexWatchItem $item): ?string
    {
        $details = $this->marketData->exchangeDetailsForCode(
            $this->marketData->exchangeCodeForIndexWatchItem($item),
        );

        if (! $details['open'] || ! $details['close']) {
            return $item->trading_times;
        }

        $timezone = $details['timezone'] ?? config('app.timezone', 'UTC');

        return "Monday-Friday {$details['open']}-{$details['close']} {$timezone}";
    }

    private function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value) || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    private function signedDecimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    private function date(mixed $value, string $timezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function timestamp(mixed $timestamp, mixed $datetime = null): ?Carbon
    {
        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            return Carbon::createFromTimestampUTC((int) $timestamp);
        }

        if (! is_string($datetime) || trim($datetime) === '') {
            return null;
        }

        try {
            return Carbon::parse($datetime, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function changePercent(?string $price, ?string $referencePrice): ?string
    {
        if ($price === null || $referencePrice === null || (float) $referencePrice === 0.0) {
            return null;
        }

        return number_format((((float) $price - (float) $referencePrice) / (float) $referencePrice) * 100, 6, '.', '');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
