<?php

namespace App\Services;

use App\Models\Depot;
use App\Models\StockHolding;
use App\Models\StockPrice;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfDocument;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WatchlistPdfReport
{
    public function __construct(
        private StockPriceFreshness $stockPriceFreshness,
        private TradingSessionPriceResolver $tradingSessionPriceResolver,
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

    public function download(): Response
    {
        $generatedAt = Carbon::now();

        return $this->pdf($generatedAt)->download($this->filename($generatedAt));
    }

    /**
     * @return array{filename: string, content: string}
     */
    public function render(): array
    {
        $generatedAt = Carbon::now();

        return [
            'filename' => $this->filename($generatedAt),
            'content' => $this->pdf($generatedAt)->output(),
        ];
    }

    public function pdf(Carbon $generatedAt): DomPdfDocument
    {
        return Pdf::loadView('pdf.watchlist', [
            'holdings' => $this->holdings(),
            'depot' => $this->activeDepotPayload(),
            'generatedAt' => $generatedAt,
        ])->setPaper('a4', 'portrait');
    }

    private function filename(Carbon $generatedAt): string
    {
        return 'watch-list-'.$generatedAt->format('Y-m-d-His').'.pdf';
    }

    /**
     * @return array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_price: ?string, start_price: ?string, end_price: ?string, end_price_24: ?string, end_price_48: ?string, start_price_date: ?string, end_price_date: ?string, end_price_24_date: ?string, end_price_48_date: ?string, historical_prices_fetching: bool, latest_price_trend: ?string, latest_price_change_pct: ?string, latest_price_tick_trend: ?string, latest_price_status: string, price_status: ?string, latest_price_fetched_at: ?string, latest_price_source: ?string, latest_price_source_url: ?string, latest_price_as_of: ?string, trading_times: ?string, venue: ?string, price_type: ?string, price_spread_pct: ?string, recent_prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, validation_errors: array<int, string>, created_at: ?string}>
     */
    private function holdings(): array
    {
        return StockHolding::query()
            ->with('latestStockPrice')
            ->orderBy('name')
            ->orderBy('isin')
            ->get()
            ->map(fn (StockHolding $holding): array => $this->holdingPayload($holding))
            ->all();
    }

    /**
     * @return array{id: int, name: string, account_balance: string, is_active: bool}|null
     */
    private function activeDepotPayload(): ?array
    {
        $depot = Depot::query()
            ->where('is_active', true)
            ->first();

        if (! $depot) {
            return null;
        }

        return [
            'id' => $depot->id,
            'name' => $depot->name,
            'account_balance' => $depot->account_balance,
            'is_active' => $depot->is_active,
        ];
    }

    /**
     * @return array{id: int, symbol: ?string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_price: ?string, start_price: ?string, end_price: ?string, end_price_24: ?string, end_price_48: ?string, start_price_date: ?string, end_price_date: ?string, end_price_24_date: ?string, end_price_48_date: ?string, historical_prices_fetching: bool, latest_price_trend: ?string, latest_price_change_pct: ?string, latest_price_tick_trend: ?string, latest_price_status: string, price_status: ?string, latest_price_fetched_at: ?string, latest_price_source: ?string, latest_price_source_url: ?string, latest_price_as_of: ?string, trading_times: ?string, venue: ?string, price_type: ?string, price_spread_pct: ?string, recent_prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, validation_errors: array<int, string>, created_at: ?string}
     */
    private function holdingPayload(StockHolding $holding): array
    {
        $latestStockPrice = $holding->latestStockPrice;
        $latestPriceStatus = $this->latestPriceStatus($holding);
        $hasCurrentPrice = in_array($latestPriceStatus, ['realtime', 'fresh', 'delayed', 'suspicious', 'unavailable_now'], true);
        $latestPrice = $hasCurrentPrice ? $this->pricePayload($holding->latest_price) : null;
        $startPrice = $this->pricePayload($holding->start_price);
        $endPrice = $this->pricePayload($holding->end_price);
        $end24Price = $this->pricePayload($holding->end_price_24);
        $sessionPrices = $this->tradingSessionPriceResolver->resolve($holding, $latestPrice);
        $sessionPriceDates = $this->sessionPriceDates($holding);
        $latestPriceAsOf = $latestStockPrice
            ? $this->storedStockPriceTimestamp($latestStockPrice, 'as_of')
            : $holding->latest_price_as_of;
        $tradingTimes = $latestStockPrice?->trading_times ?? $holding->trading_times;

        return [
            'id' => $holding->id,
            'symbol' => $holding->symbol,
            'name' => $holding->name,
            'isin' => $holding->isin,
            'wkn' => $holding->wkn,
            'exchange' => $holding->exchange,
            'mic_code' => $holding->mic_code,
            'instrument_type' => $holding->instrument_type,
            'country' => $holding->country,
            'currency' => $holding->currency,
            'latest_price' => $latestPrice,
            'start_price' => $startPrice,
            'end_price' => $endPrice,
            'end_price_24' => $end24Price,
            'end_price_48' => $this->pricePayload($holding->end_price_48),
            'start_price_date' => $sessionPriceDates['start_price_date'],
            'end_price_date' => $sessionPriceDates['end_price_date'],
            'end_price_24_date' => $sessionPriceDates['end_price_24_date'],
            'end_price_48_date' => $sessionPriceDates['end_price_48_date'],
            'historical_prices_fetching' => $sessionPrices['historical_prices_fetching'],
            'latest_price_trend' => $this->latestPriceTrend($latestPrice, $end24Price),
            'latest_price_change_pct' => $this->latestPriceChangePercent($latestPrice, $end24Price),
            'latest_price_tick_trend' => $this->latestPriceTrend($latestPrice, $this->previousStoredPrice($holding, $latestStockPrice)),
            'latest_price_status' => $latestPriceStatus,
            'price_status' => $latestPriceStatus,
            'latest_price_fetched_at' => $latestStockPrice?->fetched_at?->toIso8601String() ?? $holding->latest_price_fetched_at?->toIso8601String(),
            'latest_price_source' => $hasCurrentPrice ? ($latestStockPrice?->source_name ?? $holding->latest_price_source) : null,
            'latest_price_source_url' => $hasCurrentPrice ? ($latestStockPrice?->source_url ?? $holding->latest_price_source_url) : null,
            'latest_price_as_of' => $this->sourceDateTimePayload($latestPriceAsOf, $hasCurrentPrice),
            'trading_times' => $tradingTimes,
            'venue' => $hasCurrentPrice ? $latestStockPrice?->venue : null,
            'price_type' => $hasCurrentPrice ? ($latestStockPrice?->price_type ?? $holding->latest_price_type) : null,
            'price_spread_pct' => $hasCurrentPrice ? ($latestStockPrice?->spread_pct ?? $holding->price_spread_pct) : null,
            'recent_prices' => $this->recentStoredPrices($holding),
            'validation_errors' => $hasCurrentPrice ? ($latestStockPrice?->validation_errors ?? []) : [],
            'created_at' => $holding->created_at?->toIso8601String(),
        ];
    }

    private function latestPriceStatus(StockHolding $holding): string
    {
        $latestStockPrice = $holding->latestStockPrice;
        $storedPriceStatus = $this->storedPriceStatus($latestStockPrice);

        if ($storedPriceStatus !== null) {
            return $storedPriceStatus;
        }

        if (in_array($holding->price_status, ['realtime', 'fresh', 'delayed', 'closed_market', 'suspicious', 'unavailable_now', 'stale'], true)) {
            return $holding->price_status;
        }

        if ($latestStockPrice?->price === null && $holding->latest_price === null) {
            return $holding->latest_price_fetched_at === null ? 'missing' : 'unavailable';
        }

        $latestPriceAsOf = $latestStockPrice
            ? $this->storedStockPriceTimestamp($latestStockPrice, 'as_of')
            : $holding->latest_price_as_of;
        $tradingTimes = $latestStockPrice?->trading_times ?? $holding->trading_times;

        if ($this->stockPriceFreshness->isFresh($latestPriceAsOf, $tradingTimes)) {
            return 'fresh';
        }

        return 'stale';
    }

    private function storedPriceStatus(?StockPrice $stockPrice): ?string
    {
        if ($stockPrice?->price === null) {
            return null;
        }

        if ($stockPrice->validation_status === 'suspicious') {
            return 'suspicious';
        }

        return in_array($stockPrice->freshness_status, ['realtime', 'fresh', 'delayed', 'closed_market'], true)
            ? $stockPrice->freshness_status
            : null;
    }

    private function pricePayload(?string $price): ?string
    {
        if ($price === null) {
            return null;
        }

        return number_format((float) $price, 6, '.', '');
    }

    /**
     * @return array{start_price_date: ?string, end_price_date: ?string, end_price_24_date: ?string, end_price_48_date: ?string}
     */
    private function sessionPriceDates(StockHolding $holding): array
    {
        $tradingTimes = $holding->trading_times;
        $window = $tradingTimes === null ? null : $this->tradingWindow($tradingTimes);

        if ($window === null) {
            return [
                'start_price_date' => null,
                'end_price_date' => null,
                'end_price_24_date' => null,
                'end_price_48_date' => null,
            ];
        }

        $timezone = $this->marketTimezone($tradingTimes);
        $localNow = now()->setTimezone($timezone);
        $today = $localNow->copy()->startOfDay();
        $currentMinute = ($localNow->hour * 60) + $localNow->minute;
        $sessionDate = ! $today->isWeekend() && $currentMinute >= $window[0]
            ? $today
            : $this->previousTradingDay($today);
        $previousDate = $this->previousTradingDay($sessionDate);
        $twoAgoDate = $this->previousTradingDay($previousDate);

        return [
            'start_price_date' => $sessionDate->toDateString(),
            'end_price_date' => $sessionDate->toDateString(),
            'end_price_24_date' => $previousDate->toDateString(),
            'end_price_48_date' => $twoAgoDate->toDateString(),
        ];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function tradingWindow(string $tradingTimes): ?array
    {
        if (! preg_match('/(?<![:\d])(?<open_hour>\d{1,2}):(?<open_minute>\d{2})(?::\d{2})?\s*(?:-|to|until|bis)\s*(?<close_hour>\d{1,2}):(?<close_minute>\d{2})(?::\d{2})?/i', $tradingTimes, $matches)) {
            return null;
        }

        return [
            ((int) $matches['open_hour'] * 60) + (int) $matches['open_minute'],
            ((int) $matches['close_hour'] * 60) + (int) $matches['close_minute'],
        ];
    }

    private function marketTimezone(string $tradingTimes): string
    {
        if (preg_match('/\bEurope\/[A-Za-z_]+\b/', $tradingTimes, $matches)) {
            return $matches[0];
        }

        return 'Europe/Berlin';
    }

    private function previousTradingDay(Carbon $date): Carbon
    {
        $previousTradingDay = $date->copy()->subDay();

        while ($previousTradingDay->isWeekend()) {
            $previousTradingDay->subDay();
        }

        return $previousTradingDay;
    }

    private function latestPriceTrend(?string $latestPrice, ?string $referencePrice): ?string
    {
        if ($latestPrice === null || $referencePrice === null) {
            return null;
        }

        if (! is_numeric($latestPrice) || ! is_numeric($referencePrice)) {
            return null;
        }

        $actualPrice = (float) $latestPrice;
        $comparisonPrice = (float) $referencePrice;

        if ($actualPrice > $comparisonPrice) {
            return 'up';
        }

        if ($actualPrice < $comparisonPrice) {
            return 'down';
        }

        return 'flat';
    }

    private function latestPriceChangePercent(?string $latestPrice, ?string $referencePrice): ?string
    {
        if ($latestPrice === null || $referencePrice === null) {
            return null;
        }

        if (! is_numeric($latestPrice) || ! is_numeric($referencePrice) || (float) $referencePrice === 0.0) {
            return null;
        }

        return number_format((((float) $latestPrice - (float) $referencePrice) / (float) $referencePrice) * 100, 2, '.', '');
    }

    private function previousStoredPrice(StockHolding $holding, ?StockPrice $latestStockPrice): ?string
    {
        if ($latestStockPrice === null) {
            return null;
        }

        $query = $this->stockPriceCatalog
            ->pricesForHolding($holding)
            ->whereKeyNot($latestStockPrice->id)
            ->whereIn('source_key', EodhdMarketData::sourceKeys())
            ->whereNotNull('price');

        if ($latestStockPrice->as_of !== null) {
            $query->where(function ($query) use ($latestStockPrice): void {
                $query
                    ->where('as_of', '<', $latestStockPrice->as_of)
                    ->orWhere(function ($query) use ($latestStockPrice): void {
                        $query
                            ->where('as_of', $latestStockPrice->as_of)
                            ->where('id', '<', $latestStockPrice->id);
                    });
            });
        }

        return $query
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->value('price');
    }

    /**
     * @return array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>
     */
    private function recentStoredPrices(StockHolding $holding): array
    {
        return $this->stockPriceCatalog
            ->pricesForHolding($holding)
            ->whereIn('source_key', EodhdMarketData::sourceKeys())
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', now()->subDay())
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'price', 'currency', 'as_of', 'source_name', 'price_type'])
            ->map(fn (StockPrice $stockPrice): array => [
                'id' => $stockPrice->id,
                'price' => (string) $stockPrice->price,
                'currency' => $stockPrice->currency,
                'as_of' => $this->storedStockPriceTimestamp($stockPrice, 'as_of'),
                'source_name' => $stockPrice->source_name,
                'price_type' => $stockPrice->price_type,
            ])
            ->all();
    }

    private function sourceDateTimePayload(?string $asOf, bool $hasCurrentPrice): ?string
    {
        if (! $hasCurrentPrice || $asOf === null || trim($asOf) === '') {
            return null;
        }

        try {
            return Carbon::parse($asOf)->toIso8601String();
        } catch (Throwable) {
            return $asOf;
        }
    }

    private function storedStockPriceTimestamp(StockPrice $stockPrice, string $column): ?string
    {
        $value = $stockPrice->getRawOriginal($column);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')->toIso8601String();
    }
}
