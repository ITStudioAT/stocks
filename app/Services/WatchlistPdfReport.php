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
     * @return array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_price: ?string, start_price: ?string, end_price: ?string, latest_price_trend: ?string, latest_price_change_pct: ?string, latest_price_tick_trend: ?string, latest_price_status: string, price_status: ?string, latest_price_fetched_at: ?string, latest_price_source: ?string, latest_price_source_url: ?string, latest_price_as_of: ?string, trading_times: ?string, venue: ?string, price_type: ?string, price_spread_pct: ?string, recent_prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, validation_errors: array<int, string>, created_at: ?string}>
     */
    private function holdings(): array
    {
        return StockHolding::query()
            ->with(['latestQuote', 'latestStockPrice'])
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
     * @return array{id: int, symbol: ?string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_price: ?string, start_price: ?string, end_price: ?string, latest_price_trend: ?string, latest_price_change_pct: ?string, latest_price_tick_trend: ?string, latest_price_status: string, price_status: ?string, latest_price_fetched_at: ?string, latest_price_source: ?string, latest_price_source_url: ?string, latest_price_as_of: ?string, trading_times: ?string, venue: ?string, price_type: ?string, price_spread_pct: ?string, recent_prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, validation_errors: array<int, string>, created_at: ?string}
     */
    private function holdingPayload(StockHolding $holding): array
    {
        $latestStockPrice = $holding->latestStockPrice;
        $latestPriceStatus = $this->latestPriceStatus($holding);
        $hasCurrentPrice = in_array($latestPriceStatus, ['realtime', 'fresh', 'delayed', 'closed_market', 'suspicious', 'unavailable_now'], true);
        $latestPrice = $hasCurrentPrice ? $this->pricePayload($latestStockPrice?->price ?? $holding->latest_price) : null;
        $sessionPrices = $this->tradingSessionPriceResolver->resolve($holding, $latestPrice);
        $latestPriceAsOf = $latestStockPrice
            ? $this->storedStockPriceTimestamp($latestStockPrice, 'as_of')
            : $holding->latest_price_as_of;
        $tradingTimes = $latestStockPrice?->trading_times ?? $holding->trading_times;
        $latestPriceReference = $this->tradingSessionPriceResolver->isTradingTime($tradingTimes)
            ? $sessionPrices['start_price']
            : $sessionPrices['end_price'];

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
            'start_price' => $sessionPrices['start_price'],
            'end_price' => $sessionPrices['end_price'],
            'latest_price_trend' => $this->latestPriceTrend($latestPrice, $latestPriceReference),
            'latest_price_change_pct' => $this->latestPriceChangePercent($latestPrice, $latestPriceReference),
            'latest_price_tick_trend' => $this->latestPriceTrend($latestPrice, $this->previousStoredPrice($holding, $latestStockPrice)),
            'latest_price_status' => $latestPriceStatus,
            'price_status' => $holding->price_status,
            'latest_price_fetched_at' => $latestStockPrice?->fetched_at?->toIso8601String() ?? $holding->latest_price_fetched_at?->toIso8601String(),
            'latest_price_source' => $latestStockPrice?->source_name ?? $holding->latest_price_source,
            'latest_price_source_url' => $latestStockPrice?->source_url ?? $holding->latest_price_source_url,
            'latest_price_as_of' => $this->sourceDateTimePayload($latestPriceAsOf, $hasCurrentPrice),
            'trading_times' => $tradingTimes,
            'venue' => $hasCurrentPrice ? ($latestStockPrice?->venue ?? $holding->latestQuote?->venue) : null,
            'price_type' => $hasCurrentPrice ? ($latestStockPrice?->price_type ?? $holding->latest_price_type) : null,
            'price_spread_pct' => $hasCurrentPrice ? ($latestStockPrice?->spread_pct ?? $holding->price_spread_pct) : null,
            'recent_prices' => $this->recentStoredPrices($holding),
            'validation_errors' => $hasCurrentPrice ? ($latestStockPrice?->validation_errors ?? $holding->latestQuote?->validation_errors ?? []) : [],
            'created_at' => $holding->created_at?->toIso8601String(),
        ];
    }

    private function latestPriceStatus(StockHolding $holding): string
    {
        if (in_array($holding->price_status, ['realtime', 'fresh', 'delayed', 'closed_market', 'suspicious', 'unavailable_now', 'stale'], true)) {
            return $holding->price_status;
        }

        $latestStockPrice = $holding->latestStockPrice;

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

    private function pricePayload(?string $price): ?string
    {
        if ($price === null) {
            return null;
        }

        return number_format((float) $price, 6, '.', '');
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
            ->where('source_key', 'calculated_median')
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
            ->where('source_key', 'calculated_median')
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
