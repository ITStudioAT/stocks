<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockHoldingIntradayPrice;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\DepotTransactionBooker;
use App\Services\EodhdApiUsage;
use App\Services\EodhdMarketData;
use App\Services\IndexPriceRefreshSettings;
use App\Services\KnownInstrumentMetadataCorrections;
use App\Services\PriceRefreshScheduler;
use App\Services\StockHistoricalPriceService;
use App\Services\StockPriceCatalog;
use App\Services\StockPriceFreshness;
use App\Services\TradingSessionPriceResolver;
use App\Services\UiPreferences;
use App\Services\WatchlistPdfReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AdminDepotHoldingController extends Controller
{
    public function __construct(
        private StockPriceFreshness $stockPriceFreshness,
        private PriceRefreshScheduler $priceRefreshScheduler,
        private IndexPriceRefreshSettings $indexPriceRefreshSettings,
        private TradingSessionPriceResolver $tradingSessionPriceResolver,
        private StockPriceCatalog $stockPriceCatalog,
        private WatchlistPdfReport $watchlistPdfReport,
        private DepotTransactionBooker $depotTransactionBooker,
        private EodhdMarketData $eodhdMarketData,
        private EodhdApiUsage $eodhdApiUsage,
        private UiPreferences $uiPreferences,
        private StockHistoricalPriceService $stockHistoricalPriceService,
        private KnownInstrumentMetadataCorrections $metadataCorrections,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $activeDepot = $this->activeDepot();
        $includeCharts = $request->boolean('include_charts');
        $includeAllChartHoldings = $includeCharts && $request->boolean('all_chart_holdings');
        $chartStockId = $includeCharts ? $request->integer('chart_stock_id') : 0;
        $chartRange = $includeCharts && $request->filled('chart_range')
            ? $request->string('chart_range')->toString()
            : null;
        $includeIntradayCharts = $includeCharts && ! $includeAllChartHoldings && $this->shouldIncludeIntradayCharts($chartRange);
        $dailyChartStockId = $includeAllChartHoldings ? 0 : $chartStockId;
        $relations = [
            'latestRealtimePrice',
            'latestStockPrice',
        ];

        if ($includeCharts) {
            $historyRange = $this->stockHistoricalPriceService->range();
            $relations = [
                ...$relations,
                'dailyPrices' => fn ($query) => $this->selectedChartRelation($query, $dailyChartStockId)
                    ->whereDate('trading_date', '>=', $historyRange['from']->toDateString())
                    ->whereDate('trading_date', '<=', $historyRange['to']->toDateString())
                    ->orderBy('trading_date')
                    ->select(['id', 'stock_holding_id', 'trading_date', 'close', 'adjusted_close', 'volume', 'currency']),
            ];

            if ($includeIntradayCharts) {
                $relations['intradayCandles'] = fn ($query) => $this->selectedChartRelation($query, $chartStockId)
                    ->whereDate('trading_date', '>=', now()->subYear()->toDateString())
                    ->where('interval', '5m')
                    ->where('source_key', 'eodhd_intraday')
                    ->whereNotNull('close')
                    ->orderBy('as_of')
                    ->select(['id', 'stock_holding_id', 'trading_date', 'close', 'currency', 'as_of', 'timestamp', 'source_key']);
            }
        }

        $holdingsQuery = StockHolding::query()
            ->with($relations)
            ->orderBy('name')
            ->orderBy('isin');

        if ($includeAllChartHoldings) {
            $holdings = $holdingsQuery
                ->get()
                ->map(fn (StockHolding $holding): array => $this->holdingPayload(
                    $holding,
                    $activeDepot,
                    true,
                    false,
                ));

            return response()->json([
                'depot' => $activeDepot ? $this->depotPayload($activeDepot) : null,
                'price_refresh_settings' => $this->priceRefreshScheduler->payload(),
                'index_price_refresh_settings' => $this->indexPriceRefreshSettings->payload(),
                'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
                'ui_preferences' => $this->uiPreferences->payload(),
                'holdings' => $holdings->all(),
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $holdings->count(),
                    'total' => $holdings->count(),
                    'from' => $holdings->isEmpty() ? null : 1,
                    'to' => $holdings->isEmpty() ? null : $holdings->count(),
                ],
            ]);
        }

        $holdings = $holdingsQuery
            ->paginate(10)
            ->through(fn (StockHolding $holding): array => $this->holdingPayload(
                $holding,
                $activeDepot,
                $includeCharts && ($chartStockId <= 0 || $holding->id === $chartStockId),
                $includeIntradayCharts,
            ));

        return response()->json([
            'depot' => $activeDepot ? $this->depotPayload($activeDepot) : null,
            'price_refresh_settings' => $this->priceRefreshScheduler->payload(),
            'index_price_refresh_settings' => $this->indexPriceRefreshSettings->payload(),
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
            'ui_preferences' => $this->uiPreferences->payload(),
            'holdings' => $holdings->items(),
            'meta' => [
                'current_page' => $holdings->currentPage(),
                'last_page' => $holdings->lastPage(),
                'per_page' => $holdings->perPage(),
                'total' => $holdings->total(),
                'from' => $holdings->firstItem(),
                'to' => $holdings->lastItem(),
            ],
        ]);
    }

    public function intradayCandles(StockHolding $holding): JsonResponse
    {
        $intradayDays = $this->eodhdMarketData->ensureLastSevenTradingDayFiveMinuteCandles($holding);

        return response()->json([
            'holding' => [
                'id' => $holding->id,
                'symbol' => $holding->symbol,
                'name' => $holding->name,
                'currency' => $holding->currency,
            ],
            'intraday' => $intradayDays[0] ?? null,
            'intraday_days' => $intradayDays,
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
        ]);
    }

    public function exportPdf(): Response
    {
        return $this->watchlistPdfReport->download();
    }

    public function exchangeTradingTimes(): JsonResponse
    {
        $holdings = StockHolding::query()
            ->orderBy('exchange')
            ->orderBy('mic_code')
            ->orderBy('symbol')
            ->get(['id', 'symbol', 'exchange', 'mic_code', 'country']);
        $holdingExchangeCodes = $holdings
            ->map(fn (StockHolding $holding): string => $this->eodhdMarketData->exchangeCodeForHolding($holding))
            ->toBase();
        $indexExchangeCodes = IndexWatchItem::query()->exists()
            ? collect(['INDX'])
            : collect();

        return response()->json([
            'exchange_trading_times' => $this->eodhdMarketData->storedExchangeTradingTimesForCodes(
                $holdingExchangeCodes->merge($indexExchangeCodes),
            ),
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedHoldingData($request);

        $holding = StockHolding::query()->create([
            'symbol' => $validated['symbol'],
            'name' => $validated['name'] ?? null,
            'isin' => $validated['isin'] ?? null,
            'wkn' => $validated['wkn'] ?? null,
            'exchange' => $validated['exchange'] ?? null,
            'mic_code' => $validated['mic_code'] ?? null,
            'instrument_type' => $validated['instrument_type'] ?? null,
            'country' => $validated['country'] ?? null,
            'currency' => $validated['currency'] ?? null,
        ]);

        $this->eodhdMarketData->resolve($holding);
        $holding->refresh();
        $initialFlatexPrice = $holding->latestRealtimePrice?->price
            ?? $holding->latestStockPrice?->price
            ?? $holding->latest_price;

        if ($holding->flatex_price === null && $initialFlatexPrice !== null) {
            $holding->update([
                'flatex_price' => $initialFlatexPrice,
            ]);
            $holding->refresh();
        }

        return response()->json([
            'message' => 'Stock added to watch-list.',
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
            'holding' => $this->holdingPayload($holding, $this->activeDepot()),
        ], 201);
    }

    public function refreshPrices(Request $request): JsonResponse
    {
        $user = $request->user();
        $dispatchedRefresh = $this->priceRefreshScheduler->dispatchWatchlist($user instanceof User ? $user : null);
        $progress = $dispatchedRefresh['progress'];
        $message = trans_choice('{0} No prices queued for refresh.|{1} 1 price queued for refresh.|[2,*] :count prices queued for refresh.', $dispatchedRefresh['total_instruments']);

        if ($progress === null) {
            return response()->json([
                'message' => $message,
                'refresh' => null,
                'price_refresh_settings' => $this->priceRefreshScheduler->payload(),
                'index_price_refresh_settings' => $this->indexPriceRefreshSettings->payload(),
                'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
            ], 202);
        }

        return response()->json([
            'message' => $message,
            'refresh' => $this->refreshPayload($progress),
            'price_refresh_settings' => $this->priceRefreshScheduler->payload(),
            'index_price_refresh_settings' => $this->indexPriceRefreshSettings->payload(),
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
        ], 202);
    }

    public function refreshPriceStatus(string $refreshId, DepotHoldingPriceRefreshProgress $refreshProgress): JsonResponse
    {
        $progress = $refreshProgress->get($refreshId);

        if ($progress === null) {
            return response()->json([
                'message' => 'Price refresh not found.',
            ], 404);
        }

        return response()->json([
            'message' => $progress['message'],
            'refresh' => $this->refreshPayload($progress),
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
        ]);
    }

    public function updateFlatexPrice(Request $request, StockHolding $holding): JsonResponse
    {
        $validated = $request->validate([
            'flatex_price' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.999999'],
        ]);
        $matchingHoldingIds = $this->matchingStockHoldings($holding)->pluck('id');

        StockHolding::query()
            ->whereKey($matchingHoldingIds->all())
            ->update([
                'flatex_price' => $validated['flatex_price'] ?? null,
            ]);

        $updatedHoldings = StockHolding::query()
            ->whereKey($matchingHoldingIds->all())
            ->orderBy('id')
            ->get(['id', 'symbol', 'isin', 'wkn', 'exchange', 'mic_code', 'flatex_price'])
            ->map(fn (StockHolding $updatedHolding): array => [
                'id' => $updatedHolding->id,
                'symbol' => $updatedHolding->symbol,
                'isin' => $updatedHolding->isin,
                'wkn' => $updatedHolding->wkn,
                'exchange' => $updatedHolding->exchange,
                'mic_code' => $updatedHolding->mic_code,
                'flatex_price' => $this->pricePayload($updatedHolding->flatex_price),
            ]);
        $updatedHolding = $updatedHoldings
            ->firstWhere('id', $holding->id)
            ?? $updatedHoldings->first()
            ?? [
                'id' => $holding->id,
                'flatex_price' => $this->pricePayload($validated['flatex_price'] ?? null),
            ];

        return response()->json([
            'message' => 'Flatex price updated.',
            'holding' => $updatedHolding,
            'holdings' => $updatedHoldings->all(),
        ]);
    }

    /**
     * @return Builder<StockHolding>
     */
    private function matchingStockHoldings(StockHolding $holding): Builder
    {
        if (filled($holding->isin)) {
            return StockHolding::query()->where('isin', $holding->isin);
        }

        if (filled($holding->wkn)) {
            return StockHolding::query()->where('wkn', $holding->wkn);
        }

        if (filled($holding->symbol)) {
            return StockHolding::query()->where('symbol', $holding->symbol);
        }

        return StockHolding::query()->whereKey($holding->id);
    }

    public function destroy(StockHolding $holding): JsonResponse
    {
        $holding->delete();

        return response()->json([
            'message' => 'Stock deleted.',
        ]);
    }

    /**
     * @return array{id: int, name: string, account_balance: string, is_active: bool}|null
     */
    private function activeDepot(): ?Depot
    {
        return Depot::query()
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array{id: int, name: string, account_balance: string, is_active: bool}
     */
    private function depotPayload(Depot $depot): array
    {
        return [
            'id' => $depot->id,
            'name' => $depot->name,
            'account_balance' => $depot->account_balance,
            'is_active' => $depot->is_active,
        ];
    }

    /**
     * @return array{id: int, symbol: ?string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_price: ?string, flatex_price: ?string, start_price: ?string, end_price: ?string, end_price_24: ?string, end_price_48: ?string, start_price_date: ?string, end_price_date: ?string, end_price_24_date: ?string, end_price_48_date: ?string, historical_prices_fetching: bool, position_pieces: string, latest_price_trend: ?string, latest_price_change_pct: ?string, latest_price_tick_trend: ?string, latest_price_status: string, price_status: ?string, latest_price_fetched_at: ?string, latest_price_source: ?string, latest_price_source_url: ?string, latest_price_as_of: ?string, trading_times: ?string, venue: ?string, price_type: ?string, price_spread_pct: ?string, recent_prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, recent_prices_are_fallback: bool, intraday_prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, intraday_candles: array<int, array{id: int, trading_date: string, price: string, currency: ?string, as_of: ?string}>, daily_prices: array<int, array{trading_date: string, price: string, currency: ?string}>, validation_errors: array<int, string>, created_at: ?string}
     */
    private function holdingPayload(
        StockHolding $holding,
        ?Depot $activeDepot,
        bool $includeCharts = false,
        bool $includeIntradayCharts = true,
    ): array {
        $latestStockPrice = $holding->latestStockPrice;
        $latestStoredPrice = $holding->latestRealtimePrice ?? $latestStockPrice;
        $latestPriceStatus = $this->latestPriceStatus($holding);
        $hasCurrentPrice = in_array($latestPriceStatus, ['realtime', 'fresh', 'delayed', 'suspicious', 'unavailable_now'], true);
        $latestPrice = $hasCurrentPrice ? $this->pricePayload($holding->latest_price) : null;
        $startPrice = $this->pricePayload($holding->start_price);
        $endPrice = $this->pricePayload($holding->end_price);
        $end24Price = $this->pricePayload($holding->end_price_24);
        $sessionPrices = $this->tradingSessionPriceResolver->resolve($holding, $latestPrice);
        $sessionPriceDates = $this->sessionPriceDates($holding);
        $latestPriceAsOf = $latestStoredPrice
            ? $this->storedStockPriceTimestamp($latestStoredPrice, 'as_of')
            : $holding->latest_price_as_of;
        $tradingTimes = $latestStoredPrice?->trading_times ?? $holding->trading_times;
        $recentStoredPricePayload = $this->recentStoredPricePayload($holding);

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
            'flatex_price' => $this->pricePayload($holding->flatex_price),
            'start_price' => $startPrice,
            'end_price' => $endPrice,
            'end_price_24' => $end24Price,
            'end_price_48' => $this->pricePayload($holding->end_price_48),
            'start_price_date' => $sessionPriceDates['start_price_date'],
            'end_price_date' => $sessionPriceDates['end_price_date'],
            'end_price_24_date' => $sessionPriceDates['end_price_24_date'],
            'end_price_48_date' => $sessionPriceDates['end_price_48_date'],
            'historical_prices_fetching' => $sessionPrices['historical_prices_fetching'],
            'position_pieces' => $activeDepot
                ? $this->depotTransactionBooker->positionPieces($activeDepot, $holding)
                : '0.00000000',
            'latest_price_trend' => $this->latestPriceTrend($latestPrice, $end24Price),
            'latest_price_change_pct' => $this->latestPriceChangePercent($latestPrice, $end24Price),
            'latest_price_tick_trend' => $this->latestPriceTrend($latestPrice, $this->previousStoredPrice($holding, $latestStoredPrice)),
            'latest_price_status' => $latestPriceStatus,
            'price_status' => $latestPriceStatus,
            'latest_price_fetched_at' => $latestStoredPrice?->fetched_at?->toIso8601String() ?? $holding->latest_price_fetched_at?->toIso8601String(),
            'latest_price_source' => $hasCurrentPrice ? ($latestStoredPrice?->source_name ?? $holding->latest_price_source) : null,
            'latest_price_source_url' => $hasCurrentPrice ? ($latestStoredPrice?->source_url ?? $holding->latest_price_source_url) : null,
            'latest_price_as_of' => $this->sourceDateTimePayload($latestPriceAsOf, $hasCurrentPrice),
            'trading_times' => $tradingTimes,
            'venue' => $hasCurrentPrice ? $latestStoredPrice?->venue : null,
            'price_type' => $hasCurrentPrice ? ($latestStoredPrice?->price_type ?? $holding->latest_price_type) : null,
            'price_spread_pct' => $hasCurrentPrice ? ($latestStoredPrice?->spread_pct ?? $holding->price_spread_pct) : null,
            'recent_prices' => $recentStoredPricePayload['prices'],
            'recent_prices_are_fallback' => $recentStoredPricePayload['are_fallback'],
            'intraday_prices' => $includeCharts && $includeIntradayCharts ? $this->intradayPricePayload($holding) : [],
            'intraday_candles' => $includeCharts && $includeIntradayCharts ? $this->intradayCandlePayload($holding) : [],
            'daily_prices' => $includeCharts ? $this->dailyPricePayload($holding) : [],
            'validation_errors' => $hasCurrentPrice ? ($latestStoredPrice?->validation_errors ?? []) : [],
            'created_at' => $holding->created_at?->toIso8601String(),
        ];
    }

    private function latestPriceStatus(StockHolding $holding): string
    {
        $latestStoredPrice = $holding->latestRealtimePrice ?? $holding->latestStockPrice;
        $storedPriceStatus = $this->storedPriceStatus($latestStoredPrice);

        if ($storedPriceStatus !== null) {
            return $storedPriceStatus;
        }

        if (in_array($holding->price_status, ['realtime', 'fresh', 'delayed', 'closed_market', 'suspicious', 'unavailable_now', 'stale'], true)) {
            return $holding->price_status;
        }

        if ($latestStoredPrice?->price === null && $holding->latest_price === null) {
            return $holding->latest_price_fetched_at === null ? 'missing' : 'unavailable';
        }

        $latestPriceAsOf = $latestStoredPrice
            ? $this->storedStockPriceTimestamp($latestStoredPrice, 'as_of')
            : $holding->latest_price_as_of;
        $tradingTimes = $latestStoredPrice?->trading_times ?? $holding->trading_times;

        if ($this->stockPriceFreshness->isFresh($latestPriceAsOf, $tradingTimes)) {
            return 'fresh';
        }

        return 'stale';
    }

    private function storedPriceStatus(StockPrice|StockRealtimePrice|null $stockPrice): ?string
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

    private function selectedChartRelation(mixed $query, int $chartStockId): mixed
    {
        return $chartStockId > 0
            ? $query->where('stock_holding_id', $chartStockId)
            : $query;
    }

    private function shouldIncludeIntradayCharts(?string $chartRange): bool
    {
        return $chartRange === null || in_array($chartRange, ['today', 'today-1'], true);
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
        if (! preg_match('/(?<open_hour>\d{1,2}):(?<open_minute>\d{2})\s*(?:-|to|until|bis)\s*(?<close_hour>\d{1,2}):(?<close_minute>\d{2})/i', $tradingTimes, $matches)) {
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

    private function previousStoredPrice(StockHolding $holding, StockPrice|StockRealtimePrice|null $latestStockPrice): ?string
    {
        if ($latestStockPrice === null) {
            return null;
        }

        $query = $latestStockPrice instanceof StockRealtimePrice
            ? $holding->realtimePrices()
                ->whereKeyNot($latestStockPrice->id)
                ->whereNotNull('price')
            : $this->stockPriceCatalog
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
     * @return array{prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, are_fallback: bool}
     */
    private function recentStoredPricePayload(StockHolding $holding): array
    {
        $latestTradingDay = $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->latest('as_of')
            ->value('as_of');

        if ($latestTradingDay === null) {
            return [
                'prices' => [],
                'are_fallback' => false,
            ];
        }

        $latestTradingDate = Carbon::parse($latestTradingDay)->toDateString();

        $latestTradingDayRealtimePriceModels = $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->whereDate('as_of', $latestTradingDate)
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'price', 'currency', 'as_of', 'source_name', 'price_type']);
        $previousRealtimePrice = $this->previousTradingDayLastRealtimePrice($holding, $latestTradingDayRealtimePriceModels);
        $latestTradingDayRealtimePrices = $latestTradingDayRealtimePriceModels
            ->when($previousRealtimePrice !== null, fn ($prices) => $prices->prepend($previousRealtimePrice))
            ->map(fn (StockRealtimePrice $stockPrice): array => [
                'id' => $stockPrice->id,
                'price' => (string) $stockPrice->price,
                'currency' => $stockPrice->currency,
                'as_of' => $this->storedStockPriceTimestamp($stockPrice, 'as_of'),
                'source_name' => $stockPrice->source_name,
                'price_type' => $stockPrice->price_type,
            ]);
        $latestTradingDayPrices = $latestTradingDayRealtimePrices
            ->toBase()
            ->sortBy('as_of')
            ->values()
            ->all();

        return [
            'prices' => $latestTradingDayPrices,
            'are_fallback' => Carbon::parse($latestTradingDay)->lt(now()->subDay()) && $latestTradingDayPrices !== [],
        ];
    }

    /**
     * @param  Collection<int, StockRealtimePrice>  $recentRealtimePrices
     */
    private function previousTradingDayLastRealtimePrice(
        StockHolding $holding,
        Collection $recentRealtimePrices,
    ): ?StockRealtimePrice {
        $latestTradingDate = $recentRealtimePrices
            ->last()
            ?->as_of
            ?->toDateString();

        if ($latestTradingDate === null) {
            return null;
        }

        $hasPreviousTradingDate = $recentRealtimePrices
            ->contains(fn (StockRealtimePrice $stockPrice): bool => $stockPrice->as_of?->toDateString() < $latestTradingDate);

        if ($hasPreviousTradingDate) {
            return null;
        }

        return $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->whereDate('as_of', '<', $latestTradingDate)
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->first(['id', 'price', 'currency', 'as_of', 'source_name', 'price_type']);
    }

    /**
     * @return array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>
     */
    private function intradayPricePayload(StockHolding $holding): array
    {
        $sessionDate = $this->eodhdMarketData->intradaySessionDate($holding);

        if ($sessionDate !== null) {
            $storedCandleCount = $this->storedIntradayPriceCount($holding, $sessionDate);

            if ($storedCandleCount === 0) {
                $this->eodhdMarketData->ensureIntradaySamples($holding);
                $storedCandleCount = $this->storedIntradayPriceCount($holding, $sessionDate);
            }

            if ($storedCandleCount >= 2) {
                return $this->storedEodhdIntradayPayload($holding, $sessionDate);
            }

            $storedIntradayPrices = $this->storedSameDayIntradayFallbackPayload($holding, $sessionDate);

            if (count($storedIntradayPrices) >= 2) {
                return $storedIntradayPrices;
            }

            return $storedCandleCount > 0
                ? $this->storedEodhdIntradayPayload($holding, $sessionDate)
                : $storedIntradayPrices;
        }

        $latestStoredDate = $this->latestStoredIntradayDate($holding);

        if ($latestStoredDate === null) {
            return [];
        }

        return $this->storedEodhdIntradayPayload($holding, $latestStoredDate);
    }

    /**
     * @return array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>
     */
    private function storedEodhdIntradayPayload(StockHolding $holding, Carbon|string $tradingDate): array
    {
        return StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->whereDate('trading_date', Carbon::parse($tradingDate)->toDateString())
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->whereNotNull('close')
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'close', 'currency', 'as_of', 'timestamp', 'source_name'])
            ->map(fn (StockHoldingIntradayCandle $intradayCandle): array => [
                'id' => $intradayCandle->id,
                'price' => (string) $intradayCandle->close,
                'currency' => $intradayCandle->currency,
                'as_of' => $this->storedIntradayCandleTimestamp($intradayCandle),
                'source_name' => $intradayCandle->source_name,
                'price_type' => 'intraday',
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>
     */
    private function storedSameDayIntradayFallbackPayload(StockHolding $holding, Carbon|string $tradingDate): array
    {
        $date = Carbon::parse($tradingDate)->toDateString();
        $legacyIntradayPrices = $this->storedLegacySameDayIntradayPayload($holding, $date);

        if ($legacyIntradayPrices !== []) {
            return $legacyIntradayPrices;
        }

        return [];
    }

    /**
     * @return array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>
     */
    private function storedLegacySameDayIntradayPayload(StockHolding $holding, string $date): array
    {
        return StockHoldingIntradayPrice::query()
            ->where('stock_holding_id', $holding->id)
            ->whereDate('trading_date', $date)
            ->where('source_name', 'EODHD intraday')
            ->orderBy('sample_index')
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'price', 'currency', 'as_of', 'source_name', 'price_type'])
            ->map(fn (StockHoldingIntradayPrice $intradayPrice): array => [
                'id' => $intradayPrice->id,
                'price' => (string) $intradayPrice->price,
                'currency' => $intradayPrice->currency,
                'as_of' => $this->storedIntradayPriceTimestamp($intradayPrice),
                'source_name' => $intradayPrice->source_name,
                'price_type' => $intradayPrice->price_type,
            ])
            ->all();
    }

    private function latestStoredIntradayDate(StockHolding $holding): Carbon|string|null
    {
        return StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->whereNotNull('close')
            ->latest('trading_date')
            ->value('trading_date');
    }

    private function storedIntradayPriceCount(StockHolding $holding, Carbon|string $tradingDate): int
    {
        return StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->whereDate('trading_date', Carbon::parse($tradingDate)->toDateString())
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->whereNotNull('close')
            ->count();
    }

    /**
     * @return array<int, array{trading_date: string, price: string, volume: ?int, currency: ?string}>
     */
    private function dailyPricePayload(StockHolding $holding): array
    {
        if (! $holding->relationLoaded('dailyPrices')) {
            return [];
        }

        $dailyPrices = $holding->dailyPrices
            ->map(fn (StockHoldingDailyPrice $price): array => [
                'trading_date' => $price->trading_date->toDateString(),
                'price' => (string) ($price->adjusted_close ?? $price->close),
                'volume' => $price->volume,
                'currency' => $price->currency,
            ])
            ->filter(fn (array $price): bool => $price['price'] !== '')
            ->values()
            ->all();

        if ($dailyPrices === []) {
            return $this->dailyPricePayloadFromIntradayCandles($holding);
        }

        $latestDailyDate = collect($dailyPrices)
            ->pluck('trading_date')
            ->filter()
            ->max();

        if ($latestDailyDate === null) {
            return $dailyPrices;
        }

        $newerIntradayPrices = collect($this->dailyPricePayloadFromIntradayCandles($holding))
            ->filter(fn (array $price): bool => $price['trading_date'] > $latestDailyDate)
            ->values()
            ->all();

        return [
            ...$dailyPrices,
            ...$newerIntradayPrices,
        ];
    }

    /**
     * @return array<int, array{trading_date: string, price: string, volume: ?int, currency: ?string}>
     */
    private function dailyPricePayloadFromIntradayCandles(StockHolding $holding): array
    {
        $latestCandlePerDay = StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->whereDate('trading_date', '>=', now()->subYear()->toDateString())
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->whereNotNull('close')
            ->select('trading_date', DB::raw('MAX(as_of) as latest_as_of'))
            ->groupBy('trading_date');
        $volumePerDay = StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->whereDate('trading_date', '>=', now()->subYear()->toDateString())
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->select('trading_date', DB::raw('SUM(volume) as daily_volume'))
            ->groupBy('trading_date');

        return StockHoldingIntradayCandle::query()
            ->joinSub($latestCandlePerDay, 'latest_candle_per_day', function ($join): void {
                $join
                    ->on('stock_holding_intraday_candles.trading_date', '=', 'latest_candle_per_day.trading_date')
                    ->on('stock_holding_intraday_candles.as_of', '=', 'latest_candle_per_day.latest_as_of');
            })
            ->leftJoinSub($volumePerDay, 'volume_per_day', function ($join): void {
                $join->on('stock_holding_intraday_candles.trading_date', '=', 'volume_per_day.trading_date');
            })
            ->where('stock_holding_intraday_candles.stock_holding_id', $holding->id)
            ->orderBy('stock_holding_intraday_candles.trading_date')
            ->get([
                'stock_holding_intraday_candles.trading_date',
                'stock_holding_intraday_candles.close',
                'stock_holding_intraday_candles.currency',
                DB::raw('volume_per_day.daily_volume as volume'),
            ])
            ->map(fn (StockHoldingIntradayCandle $price): array => [
                'trading_date' => $price->trading_date->toDateString(),
                'price' => (string) $price->close,
                'volume' => $price->volume === null ? null : (int) $price->volume,
                'currency' => $price->currency,
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

    private function storedStockPriceTimestamp(StockPrice|StockRealtimePrice $stockPrice, string $column): ?string
    {
        $value = $stockPrice->{$column};

        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }

    private function storedIntradayPriceTimestamp(StockHoldingIntradayPrice $intradayPrice): ?string
    {
        $value = $intradayPrice->getRawOriginal('as_of');

        if ($value === null) {
            return null;
        }

        return Carbon::parse($value, 'UTC')
            ->setTimezone(config('app.timezone'))
            ->toIso8601String();
    }

    /**
     * @return array<int, array{id: int, trading_date: string, price: string, currency: ?string, as_of: ?string}>
     */
    private function intradayCandlePayload(StockHolding $holding): array
    {
        if (! $holding->relationLoaded('intradayCandles')) {
            return [];
        }

        return $holding->intradayCandles
            ->map(fn (StockHoldingIntradayCandle $intradayCandle): array => [
                'id' => $intradayCandle->id,
                'trading_date' => $intradayCandle->trading_date->toDateString(),
                'price' => (string) $intradayCandle->close,
                'currency' => $intradayCandle->currency,
                'as_of' => $this->storedIntradayCandleTimestamp($intradayCandle),
            ])
            ->filter(fn (array $intradayCandle): bool => $intradayCandle['price'] !== '')
            ->values()
            ->all();
    }

    private function storedIntradayCandleTimestamp(StockHoldingIntradayCandle $intradayCandle): ?string
    {
        if ($intradayCandle->timestamp !== null) {
            return Carbon::createFromTimestampUTC($intradayCandle->timestamp)
                ->setTimezone(config('app.timezone'))
                ->toIso8601String();
        }

        $value = $intradayCandle->as_of;

        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }

    /**
     * @return array{symbol: string, name?: string, isin?: string, wkn?: string, exchange?: string, mic_code?: string, instrument_type?: string, country?: string, currency?: string}
     */
    private function validatedHoldingData(Request $request): array
    {
        $request->merge([
            'symbol' => $request->filled('symbol') ? Str::upper(trim((string) $request->input('symbol'))) : null,
            'name' => $request->filled('name') ? trim((string) $request->input('name')) : null,
            'isin' => $request->filled('isin') ? Str::upper(trim((string) $request->input('isin'))) : null,
            'wkn' => $request->filled('wkn') ? Str::upper(trim((string) $request->input('wkn'))) : null,
            'exchange' => $request->filled('exchange') ? trim((string) $request->input('exchange')) : null,
            'mic_code' => $request->filled('mic_code') ? Str::upper(trim((string) $request->input('mic_code'))) : null,
            'instrument_type' => $request->filled('instrument_type') ? trim((string) $request->input('instrument_type')) : null,
            'country' => $request->filled('country') ? trim((string) $request->input('country')) : null,
            'currency' => $request->filled('currency') ? Str::upper(trim((string) $request->input('currency'))) : null,
        ]);

        $request->merge($this->metadataCorrections->apply($request->all()));

        return $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'isin' => [
                'nullable',
                'string',
                'size:12',
                Rule::unique(StockHolding::class, 'isin'),
            ],
            'wkn' => [
                'nullable',
                'string',
                'size:6',
                Rule::unique(StockHolding::class, 'wkn'),
            ],
            'exchange' => ['nullable', 'string', 'max:255'],
            'mic_code' => ['nullable', 'string', 'max:32'],
            'instrument_type' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:8'],
        ]);
    }

    /**
     * @param  array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}  $progress
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}
     */
    private function refreshPayload(array $progress): array
    {
        return [
            'refresh_id' => $progress['refresh_id'],
            'status' => $progress['status'],
            'processed' => $progress['processed'],
            'total' => $progress['total'],
            'step' => $progress['step'],
            'message' => $progress['message'],
            'current' => $progress['current'],
            'started_at' => $progress['started_at'],
            'finished_at' => $progress['finished_at'],
            'error' => $progress['error'],
        ];
    }
}
