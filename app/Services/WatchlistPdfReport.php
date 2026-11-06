<?php

namespace App\Services;

use App\Models\Depot;
use App\Models\StockHolding;
use App\Models\StockPrice;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfDocument;
use Illuminate\Database\Eloquent\Model;
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
        $holdings = StockHolding::query()
            ->with(['latestStockPrice', 'latestRealtimePrice'])
            ->orderBy('name')
            ->orderBy('isin')
            ->get();
        app(StockHoldingPriceReadBatch::class)->load($holdings);

        return $holdings->map(fn (StockHolding $holding): array => MarketDataProvenance::decorate($this->holdingPayload($holding)))
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
     * @return array{id: int, symbol: ?string, name: ?string, subtitle: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_price: ?string, start_price: ?string, end_price: ?string, end_price_24: ?string, end_price_48: ?string, start_price_date: ?string, end_price_date: ?string, end_price_24_date: ?string, end_price_48_date: ?string, historical_prices_fetching: bool, latest_price_trend: ?string, latest_price_change_pct: ?string, latest_price_tick_trend: ?string, latest_price_status: string, price_status: ?string, latest_price_fetched_at: ?string, latest_price_source: ?string, latest_price_source_url: ?string, latest_price_as_of: ?string, trading_times: ?string, venue: ?string, price_type: ?string, price_spread_pct: ?string, recent_prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, validation_errors: array<int, string>, created_at: ?string}
     */
    private function holdingPayload(StockHolding $holding): array
    {
        $latestStockPrice = collect([$holding->latestStockPrice, $holding->readRealtimePrices->last(), $holding->readLatestCandle])
            ->filter(fn (?Model $price): bool => ($price?->price ?? $price?->close) !== null && MarketDataProvenance::forRecord($price) === ['free'])
            ->sortByDesc(fn (Model $price): string => $price->getRawOriginal('as_of') ?? '')->first();
        $latestPriceStatus = $this->latestPriceStatus($holding);
        $hasCurrentPrice = ($latestStockPrice?->price ?? $latestStockPrice?->close) !== null;
        $latestPrice = $hasCurrentPrice ? $this->pricePayload($latestStockPrice?->price ?? $latestStockPrice?->close ?? $holding->latest_price) : null;
        $startPrice = $this->pricePayload($holding->start_price);
        $endPrice = $latestPrice;
        $end24Price = $this->pricePayload($holding->end_price_24);
        $sessionPrices = $this->tradingSessionPriceResolver->resolve($holding, $latestPrice);
        $sessionPriceDates = $this->sessionPriceDates($holding);
        $latestPriceAsOf = $latestStockPrice
            ? $this->storedStockPriceTimestamp($latestStockPrice, 'as_of')
            : $holding->latest_price_as_of;
        $tradingTimes = $latestStockPrice?->trading_times ?? $holding->trading_times;
        $previousCloses = app(StockPreviousCloseResolver::class)->forObservation($holding, $latestStockPrice);
        $end24Price = $this->pricePayload($previousCloses['previous_close']);
        $changeReference = app(StockPreviousCloseResolver::class)->comparisonReference($holding, $latestStockPrice ?? $latestPriceAsOf, $end24Price, $previousCloses['previous_close_date']);
        $central = MarketPriceStore::enabled();
        $tradingDate = $central ? app(StockPreviousCloseResolver::class)->tradingDateOf($holding, $latestStockPrice)
            : ($latestPriceAsOf === null ? null : Carbon::parse($latestPriceAsOf)->setTimezone(config('app.timezone'))->toDateString());
        $startRecord = $holding->readRealtimePrices->concat($holding->readIntradayCandles)
            ->filter(fn (Model $price): bool => $central ? app(StockPreviousCloseResolver::class)->tradingDateOf($holding, $price) === $tradingDate
                : Carbon::parse($price->getRawOriginal('as_of'), 'UTC')->setTimezone(config('app.timezone'))->toDateString() === $tradingDate)
            ->sortBy(fn (Model $price): string => $price->getRawOriginal('as_of'))->first();
        $startPrice = $this->pricePayload($startRecord?->price ?? $startRecord?->close);
        $endPrice = $latestPrice ?? $endPrice;

        return [
            'id' => $holding->id,
            'symbol' => $holding->symbol,
            'name' => $holding->name,
            'subtitle' => $holding->subtitle,
            'isin' => $holding->isin,
            'wkn' => $holding->wkn,
            'exchange' => $holding->exchange,
            'mic_code' => $holding->mic_code,
            'instrument_type' => $holding->instrument_type,
            'country' => $holding->country,
            'currency' => $holding->currency,
            'latest_price' => $latestPrice,
            'latest_price_source_markers' => $latestPrice === null ? [] : MarketDataProvenance::forRecord($latestStockPrice),
            'start_price' => $startPrice,
            'start_price_source_markers' => MarketDataProvenance::forRecord($startRecord),
            'end_price' => $endPrice,
            'end_price_source_markers' => $latestPrice === null ? [] : MarketDataProvenance::forRecord($latestStockPrice),
            'end_price_24' => $this->pricePayload($previousCloses['previous_close']),
            'end_price_48' => $this->pricePayload($previousCloses['earlier_close']),
            'end_price_24_source_markers' => $previousCloses['previous_close_source_markers'],
            'end_price_48_source_markers' => $previousCloses['earlier_close_source_markers'],
            'start_price_date' => $startRecord === null ? $sessionPriceDates['start_price_date'] : $tradingDate,
            'end_price_date' => $latestPrice === null ? $sessionPriceDates['end_price_date'] : $tradingDate,
            'end_price_24_date' => $previousCloses['previous_close_date'],
            'end_price_48_date' => $previousCloses['earlier_close_date'],
            'historical_prices_fetching' => $sessionPrices['historical_prices_fetching'],
            'latest_price_trend' => $this->latestPriceTrend($latestPrice, $changeReference),
            'latest_price_change_pct' => $this->latestPriceChangePercent($latestPrice, $changeReference),
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

    private function previousStoredPrice(StockHolding $holding, ?Model $latestStockPrice): ?string
    {
        if ($latestStockPrice === null) {
            return null;
        }

        $previous = $holding->readRealtimePrices->concat($holding->readIntradayCandles)->concat($holding->readPreviousCloseObservations)
            ->filter(fn (Model $price): bool => $price->getRawOriginal('as_of') < $latestStockPrice->getRawOriginal('as_of'))
            ->sortByDesc(fn (Model $price): string => $price->getRawOriginal('as_of'))->first();

        return $previous?->price ?? $previous?->close;
    }

    /**
     * @return array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>
     */
    private function recentStoredPrices(StockHolding $holding): array
    {
        $historical = $this->stockPriceCatalog
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
                'source_markers' => MarketDataProvenance::forRecord($stockPrice),
                'price_type' => $stockPrice->price_type,
            ])->all();
        $current = $holding->readRealtimePrices->concat($holding->readIntradayCandles)
            ->filter(fn (Model $price): bool => Carbon::parse($price->getRawOriginal('as_of'), 'UTC')->gte(now()->subDay()))
            ->map(fn (Model $price): array => [
                'id' => $price->id, 'price' => (string) ($price->price ?? $price->close), 'currency' => $price->currency,
                'as_of' => $this->storedStockPriceTimestamp($price, 'as_of'), 'source_name' => $price->source_name,
                'source_markers' => MarketDataProvenance::forRecord($price), 'price_type' => $price->price_type ?? $price->interval,
            ]);

        return collect($historical)->concat($current)->unique(fn (array $price): string => implode('|', [$price['as_of'], $price['source_name'], $price['price']]))
            ->sortBy('as_of')->values()->all();
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

    private function storedStockPriceTimestamp(Model $stockPrice, string $column): ?string
    {
        $value = $stockPrice->getRawOriginal($column);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')->toIso8601String();
    }
}
