<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\DepotTransaction;
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
use App\Services\EndOfDayDataUpdateScheduler;
use App\Services\EodhdApiUsage;
use App\Services\EodhdMarketData;
use App\Services\IndexDataUpdateScheduler;
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
    private const WEEKLY_INTRADAY_SAMPLES_PER_DAY = 5;

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
        private EndOfDayDataUpdateScheduler $endOfDayDataUpdateScheduler,
        private IndexDataUpdateScheduler $indexDataUpdateScheduler,
        private UiPreferences $uiPreferences,
        private StockHistoricalPriceService $stockHistoricalPriceService,
        private KnownInstrumentMetadataCorrections $metadataCorrections,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $activeDepot = $this->activeDepot();
        $includeCharts = $request->boolean('include_charts');
        $includeAllChartHoldings = $includeCharts && $request->boolean('all_chart_holdings');
        $includeAllHoldings = $request->boolean('all') || $includeAllChartHoldings;
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

        if ($activeDepot) {
            $relations['depotTransactions'] = fn ($query) => $query
                ->where('depot_id', $activeDepot->id)
                ->whereIn('type', DepotTransaction::StockTypes)
                ->orderBy('booked_at')
                ->orderBy('id')
                ->select(['id', 'stock_holding_id', 'type', 'pieces', 'total_amount', 'currency', 'booked_at']);
        }

        $holdingsQuery = StockHolding::query()
            ->with($relations)
            ->orderBy('name')
            ->orderBy('isin');

        if ($includeAllHoldings) {
            $holdings = $holdingsQuery
                ->get()
                ->map(fn (StockHolding $holding): array => $this->holdingPayload(
                    $holding,
                    $activeDepot,
                    $includeAllChartHoldings,
                    false,
                ));

            return response()->json([
                'depot' => $activeDepot ? $this->depotPayload($activeDepot) : null,
                'price_refresh_settings' => $this->priceRefreshScheduler->payload(),
                'index_price_refresh_settings' => $this->indexPriceRefreshSettings->payload(),
                'end_of_day_data_update_settings' => $this->endOfDayDataUpdateScheduler->payload(),
                'index_data_update_settings' => $this->indexDataUpdateScheduler->payload(),
                'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
                'ui_preferences' => $this->uiPreferences->payload($request->user()),
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
                $chartRange,
            ));

        return response()->json([
            'depot' => $activeDepot ? $this->depotPayload($activeDepot) : null,
            'price_refresh_settings' => $this->priceRefreshScheduler->payload(),
            'index_price_refresh_settings' => $this->indexPriceRefreshSettings->payload(),
            'end_of_day_data_update_settings' => $this->endOfDayDataUpdateScheduler->payload(),
            'index_data_update_settings' => $this->indexDataUpdateScheduler->payload(),
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
            'ui_preferences' => $this->uiPreferences->payload($request->user()),
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
        $intradayDays = $this->intradayDaysWithLatestRealtimePrices(
            $holding,
            $this->eodhdMarketData->ensureLastSevenTradingDayFiveMinuteCandles($holding),
        );

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

    public function latestRealtimePrices(StockHolding $holding): JsonResponse
    {
        $latestRealtimePrice = $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->first(['id', 'as_of']);

        if ($latestRealtimePrice === null) {
            return response()->json([
                'holding' => [
                    'id' => $holding->id,
                    'symbol' => $holding->symbol,
                    'name' => $holding->name,
                    'currency' => $holding->currency,
                ],
                'date' => null,
                'entries' => [],
            ]);
        }

        $latestTradingDate = $this->storedStockPriceDateTime($latestRealtimePrice, 'as_of')
            ?->setTimezone(config('app.timezone'))
            ->toDateString();

        if ($latestTradingDate === null) {
            return response()->json([
                'holding' => [
                    'id' => $holding->id,
                    'symbol' => $holding->symbol,
                    'name' => $holding->name,
                    'currency' => $holding->currency,
                ],
                'date' => null,
                'entries' => [],
            ]);
        }

        $dayStart = Carbon::parse($latestTradingDate, config('app.timezone'))->startOfDay()->utc();
        $dayEnd = Carbon::parse($latestTradingDate, config('app.timezone'))->endOfDay()->utc();
        $entries = $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $dayStart)
            ->where('as_of', '<=', $dayEnd)
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'price', 'currency', 'as_of', 'source_name', 'price_type', 'venue'])
            ->map(fn (StockRealtimePrice $stockPrice): array => [
                'id' => $stockPrice->id,
                'price' => (string) $stockPrice->price,
                'currency' => $stockPrice->currency,
                'as_of' => $this->storedStockPriceTimestamp($stockPrice, 'as_of'),
                'source_name' => $stockPrice->source_name,
                'price_type' => $stockPrice->price_type,
                'venue' => $stockPrice->venue,
            ])
            ->all();

        return response()->json([
            'holding' => [
                'id' => $holding->id,
                'symbol' => $holding->symbol,
                'name' => $holding->name,
                'currency' => $holding->currency,
            ],
            'date' => $latestTradingDate,
            'entries' => $entries,
        ]);
    }

    public function latestIntradayCandles(StockHolding $holding): JsonResponse
    {
        $latestTradingDates = $holding->intradayCandles()
            ->whereNotNull('close')
            ->whereNotNull('trading_date')
            ->select('trading_date')
            ->distinct()
            ->orderByDesc('trading_date')
            ->limit(7)
            ->pluck('trading_date')
            ->map(fn (Carbon|string $tradingDate): string => Carbon::parse($tradingDate)->toDateString())
            ->sort()
            ->values();

        if ($latestTradingDates->isEmpty()) {
            return response()->json([
                'holding' => [
                    'id' => $holding->id,
                    'symbol' => $holding->symbol,
                    'name' => $holding->name,
                    'currency' => $holding->currency,
                ],
                'dates' => [],
                'entries' => [],
            ]);
        }

        $latestTradingDateLookup = $latestTradingDates->flip();
        $entries = $holding->intradayCandles()
            ->whereNotNull('close')
            ->whereDate('trading_date', '>=', $latestTradingDates->first())
            ->whereDate('trading_date', '<=', $latestTradingDates->last())
            ->orderByDesc('trading_date')
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->get(['id', 'trading_date', 'close', 'currency', 'as_of', 'timestamp'])
            ->filter(fn (StockHoldingIntradayCandle $intradayCandle): bool => $latestTradingDateLookup->has(
                $intradayCandle->trading_date->toDateString(),
            ))
            ->values()
            ->map(fn (StockHoldingIntradayCandle $intradayCandle): array => [
                'id' => $intradayCandle->id,
                'trading_date' => $intradayCandle->trading_date->toDateString(),
                'price' => (string) $intradayCandle->close,
                'currency' => $intradayCandle->currency,
                'as_of' => $this->storedIntradayCandleTimestamp($intradayCandle),
            ])
            ->all();

        return response()->json([
            'holding' => [
                'id' => $holding->id,
                'symbol' => $holding->symbol,
                'name' => $holding->name,
                'currency' => $holding->currency,
            ],
            'dates' => $latestTradingDates->all(),
            'entries' => $entries,
        ]);
    }

    public function latestEndOfDayPrices(StockHolding $holding): JsonResponse
    {
        $entries = $this->stockPriceCatalog->pricesForHolding($holding)
            ->where('price_type', 'historical_eod')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->get(['id', 'price', 'currency', 'as_of'])
            ->unique(fn (StockPrice $stockPrice): string => $this->storedStockPriceDateTime($stockPrice, 'as_of')
                ?->setTimezone(config('app.timezone'))
                ->toDateString() ?? "row-{$stockPrice->id}")
            ->take(30)
            ->values()
            ->map(fn (StockPrice $stockPrice): array => [
                'id' => $stockPrice->id,
                'price' => (string) $stockPrice->price,
                'currency' => $stockPrice->currency,
                'as_of' => $this->storedStockPriceTimestamp($stockPrice, 'as_of'),
            ])
            ->all();

        return response()->json([
            'holding' => [
                'id' => $holding->id,
                'symbol' => $holding->symbol,
                'name' => $holding->name,
                'currency' => $holding->currency,
            ],
            'entries' => $entries,
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
        $message = $progress['message']
            ?? trans_choice('{0} No stock realtime prices synced.|{1} 1 stock realtime price synced.|[2,*] :count stock realtime prices synced.', $dispatchedRefresh['total_holdings']);

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
        $progress = $refreshProgress->getStored($refreshId);

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
     * @return array{id: int, symbol: ?string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_price: ?string, flatex_price: ?string, start_price: ?string, end_price: ?string, end_price_24: ?string, end_price_48: ?string, start_price_date: ?string, end_price_date: ?string, end_price_24_date: ?string, end_price_48_date: ?string, historical_prices_fetching: bool, position_pieces: string, latest_price_trend: ?string, latest_price_change_pct: ?string, latest_price_tick_trend: ?string, latest_price_status: string, price_status: ?string, latest_price_fetched_at: ?string, latest_price_source: ?string, latest_price_source_url: ?string, latest_price_as_of: ?string, trading_times: ?string, venue: ?string, price_type: ?string, price_spread_pct: ?string, recent_prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, recent_prices_are_fallback: bool, intraday_prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, intraday_candles: array<int, array{id: int, trading_date: string, price: string, currency: ?string, as_of: ?string}>, daily_prices: array<int, array{trading_date: string, price: string, volume: ?int, currency: ?string}>, depot_transactions: array<int, array{id: int, type: string, pieces: ?string, total_amount: string, currency: ?string, booked_at: ?string}>, validation_errors: array<int, string>, created_at: ?string}
     */
    private function holdingPayload(
        StockHolding $holding,
        ?Depot $activeDepot,
        bool $includeCharts = false,
        bool $includeIntradayCharts = true,
        ?string $chartRange = null,
    ): array {
        $dashboardPriceSourceDate = $this->dashboardPriceSourceDate($holding);
        $relevantTradingDate = $dashboardPriceSourceDate['date'];
        $dashboardSessionPrices = $this->dashboardSessionPrices($holding, $dashboardPriceSourceDate);
        $latestStoredPrice = $dashboardSessionPrices['latest_price'];
        $latestPriceStatus = $this->latestPriceStatus($holding);
        $latestPrice = $this->pricePayload($this->dashboardPriceValue($latestStoredPrice));
        $startPrice = $this->pricePayload($this->dashboardPriceValue($dashboardSessionPrices['start_price']));
        $latestEndPrice = $this->latestEndPrice($holding);
        $endPrice = $this->pricePayload($latestEndPrice['price'] ?? $holding->end_price);
        $endOfDaySessionPrices = $this->endOfDaySessionPrices($holding, $relevantTradingDate);
        $end24Price = $endOfDaySessionPrices['end_price_24'];
        $sessionPrices = $this->tradingSessionPriceResolver->resolve($holding, $latestPrice);
        $sessionPriceDates = $this->sessionPriceDates($holding);
        $latestPriceAsOf = $this->dashboardPriceTimestamp($latestStoredPrice);
        $tradingTimes = $latestStoredPrice instanceof StockRealtimePrice
            ? $latestStoredPrice->trading_times ?? $holding->trading_times
            : $holding->trading_times;
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
            'end_price_48' => $endOfDaySessionPrices['end_price_48'],
            'start_price_date' => $relevantTradingDate ?? $sessionPriceDates['start_price_date'],
            'end_price_date' => $latestEndPrice['date'] ?? $relevantTradingDate ?? $sessionPriceDates['end_price_date'],
            'end_price_24_date' => $endOfDaySessionPrices['end_price_24_date'],
            'end_price_48_date' => $endOfDaySessionPrices['end_price_48_date'],
            'historical_prices_fetching' => $sessionPrices['historical_prices_fetching'],
            'position_pieces' => $activeDepot
                ? $this->depotTransactionBooker->positionPieces($activeDepot, $holding)
                : '0.00000000',
            'latest_price_trend' => $this->latestPriceTrend($latestPrice, $end24Price),
            'latest_price_change_pct' => $this->latestPriceChangePercent($latestPrice, $end24Price),
            'latest_price_tick_trend' => $this->latestPriceTrend($latestPrice, $this->previousStoredPrice($holding, $latestStoredPrice)),
            'latest_price_status' => $latestPriceStatus,
            'price_status' => $latestPriceStatus,
            'latest_price_fetched_at' => $latestStoredPrice instanceof StockHoldingIntradayCandle
                ? null
                : $this->dashboardPriceFetchedTimestamp($latestStoredPrice) ?? $holding->latest_price_fetched_at?->toIso8601String(),
            'latest_price_source' => $this->dashboardPriceSourceName($latestStoredPrice),
            'latest_price_source_url' => $this->dashboardPriceSourceUrl($latestStoredPrice),
            'latest_price_as_of' => $this->sourceDateTimePayload($latestPriceAsOf, $latestStoredPrice !== null),
            'trading_times' => $tradingTimes,
            'venue' => $latestStoredPrice instanceof StockRealtimePrice ? $latestStoredPrice->venue : null,
            'price_type' => $latestStoredPrice instanceof StockRealtimePrice ? $latestStoredPrice->price_type : ($latestStoredPrice ? 'intraday' : null),
            'price_spread_pct' => $latestStoredPrice instanceof StockRealtimePrice ? $latestStoredPrice->spread_pct : null,
            'recent_prices' => $recentStoredPricePayload['prices'],
            'recent_prices_are_fallback' => $recentStoredPricePayload['are_fallback'],
            'intraday_prices' => $includeCharts
                ? $this->intradayPricePayloadForChartRange($holding, $chartRange, $includeIntradayCharts)
                : [],
            'intraday_candles' => $includeCharts && $includeIntradayCharts ? $this->intradayCandlePayload($holding) : [],
            'daily_prices' => $includeCharts ? $this->dailyPricePayload($holding) : [],
            'depot_transactions' => $this->depotTransactionPayload($holding),
            'validation_errors' => $latestStoredPrice instanceof StockRealtimePrice ? $latestStoredPrice->validation_errors ?? [] : [],
            'created_at' => $holding->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{id: int, type: string, pieces: ?string, total_amount: string, currency: ?string, booked_at: ?string}>
     */
    private function depotTransactionPayload(StockHolding $holding): array
    {
        if (! $holding->relationLoaded('depotTransactions')) {
            return [];
        }

        return $holding->depotTransactions
            ->map(fn (DepotTransaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'pieces' => $transaction->pieces,
                'total_amount' => $transaction->total_amount,
                'currency' => $transaction->currency,
                'booked_at' => $transaction->booked_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{end_price_24: ?string, end_price_48: ?string, end_price_24_date: ?string, end_price_48_date: ?string}
     */
    private function endOfDaySessionPrices(StockHolding $holding, ?string $relevantTradingDate): array
    {
        $query = $this->stockPriceCatalog->pricesForHolding($holding)
            ->where('price_type', 'historical_eod')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->orderByDesc('as_of')
            ->orderByDesc('id');

        if ($relevantTradingDate !== null) {
            $query->where('as_of', '<', Carbon::parse($relevantTradingDate, config('app.timezone'))->startOfDay()->utc());
        }

        $prices = $query
            ->get(['id', 'price', 'as_of'])
            ->unique(fn (StockPrice $stockPrice): string => $this->storedStockPriceDateTime($stockPrice, 'as_of')
                ?->setTimezone(config('app.timezone'))
                ->toDateString() ?? "row-{$stockPrice->id}")
            ->take(2)
            ->values();

        $end24Price = $prices->get(0);
        $end48Price = $prices->get(1);

        return [
            'end_price_24' => $end24Price instanceof StockPrice ? $this->pricePayload($end24Price->price) : null,
            'end_price_48' => $end48Price instanceof StockPrice ? $this->pricePayload($end48Price->price) : null,
            'end_price_24_date' => $end24Price instanceof StockPrice ? $this->endOfDayDate($end24Price) : null,
            'end_price_48_date' => $end48Price instanceof StockPrice ? $this->endOfDayDate($end48Price) : null,
        ];
    }

    private function relevantTradingDate(StockHolding $holding): ?string
    {
        return $this->dashboardPriceSourceDate($holding)['date'];
    }

    /**
     * @return array{source: 'intraday'|'realtime'|null, date: ?string}
     */
    private function dashboardPriceSourceDate(StockHolding $holding): array
    {
        $latestIntradayDate = $this->latestStoredIntradayDate($holding);
        $intradayDate = $latestIntradayDate === null
            ? null
            : Carbon::parse($latestIntradayDate)->toDateString();
        $realtimeDate = $this->latestRealtimeTradingDate($holding);

        if ($realtimeDate !== null && ($intradayDate === null || $realtimeDate > $intradayDate)) {
            return [
                'source' => 'realtime',
                'date' => $realtimeDate,
            ];
        }

        if ($intradayDate !== null) {
            return [
                'source' => 'intraday',
                'date' => $intradayDate,
            ];
        }

        return [
            'source' => null,
            'date' => null,
        ];
    }

    /**
     * @return array{price: ?string, date: ?string}
     */
    private function latestEndPrice(StockHolding $holding): array
    {
        $latestRealtimePrice = $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->first(['id', 'price', 'as_of']);
        $latestIntradayCandle = $holding->intradayCandles()
            ->whereNotNull('close')
            ->whereNotNull('as_of')
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->first(['id', 'trading_date', 'close', 'as_of']);

        if ($latestRealtimePrice === null && $latestIntradayCandle === null) {
            return [
                'price' => null,
                'date' => null,
            ];
        }

        if ($latestRealtimePrice === null) {
            return [
                'price' => (string) $latestIntradayCandle?->close,
                'date' => $latestIntradayCandle?->trading_date?->toDateString(),
            ];
        }

        if ($latestIntradayCandle === null) {
            $latestRealtimeDate = $this->storedStockPriceDateTime($latestRealtimePrice, 'as_of')
                ?->setTimezone(config('app.timezone'))
                ->toDateString();

            return [
                'price' => (string) $latestRealtimePrice->price,
                'date' => $latestRealtimeDate,
            ];
        }

        $latestRealtimeAsOf = $this->storedStockPriceDateTime($latestRealtimePrice, 'as_of');
        $latestIntradayAsOf = $this->storedIntradayCandleDateTime($latestIntradayCandle);

        if ($latestRealtimeAsOf === null || ($latestIntradayAsOf !== null && $latestIntradayAsOf->greaterThan($latestRealtimeAsOf))) {
            return [
                'price' => (string) $latestIntradayCandle->close,
                'date' => $latestIntradayCandle->trading_date?->toDateString(),
            ];
        }

        return [
            'price' => (string) $latestRealtimePrice->price,
            'date' => $latestRealtimeAsOf?->setTimezone(config('app.timezone'))->toDateString(),
        ];
    }

    /**
     * @return array{start_price: ?StockRealtimePrice, latest_price: ?StockRealtimePrice}
     */
    private function realtimeSessionPrices(StockHolding $holding, ?string $relevantTradingDate): array
    {
        if ($relevantTradingDate === null) {
            return [
                'start_price' => null,
                'latest_price' => null,
            ];
        }

        $dayStart = Carbon::parse($relevantTradingDate, config('app.timezone'))->startOfDay()->utc();
        $dayEnd = Carbon::parse($relevantTradingDate, config('app.timezone'))->endOfDay()->utc();
        $prices = $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $dayStart)
            ->where('as_of', '<=', $dayEnd)
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'price', 'currency', 'as_of', 'fetched_at', 'source_name', 'source_url', 'venue', 'price_type', 'spread_pct', 'validation_errors', 'trading_times']);

        return [
            'start_price' => $prices->first(),
            'latest_price' => $prices->last(),
        ];
    }

    /**
     * @param  array{source: 'intraday'|'realtime'|null, date: ?string}  $sourceDate
     * @return array{start_price: StockRealtimePrice|StockHoldingIntradayCandle|null, latest_price: StockRealtimePrice|StockHoldingIntradayCandle|null}
     */
    private function dashboardSessionPrices(StockHolding $holding, array $sourceDate): array
    {
        if ($sourceDate['source'] === 'realtime') {
            return $this->realtimeSessionPrices($holding, $sourceDate['date']);
        }

        if ($sourceDate['source'] === 'intraday') {
            return $this->intradayCandleSessionPrices($holding, $sourceDate['date']);
        }

        return [
            'start_price' => null,
            'latest_price' => null,
        ];
    }

    /**
     * @return array{start_price: ?StockHoldingIntradayCandle, latest_price: ?StockHoldingIntradayCandle}
     */
    private function intradayCandleSessionPrices(StockHolding $holding, ?string $tradingDate): array
    {
        if ($tradingDate === null) {
            return [
                'start_price' => null,
                'latest_price' => null,
            ];
        }

        $candles = $holding->intradayCandles()
            ->whereDate('trading_date', $tradingDate)
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->whereNotNull('close')
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'trading_date', 'close', 'currency', 'as_of', 'timestamp', 'source_name', 'source_url']);

        return [
            'start_price' => $candles->first(),
            'latest_price' => $candles->last(),
        ];
    }

    private function dashboardPriceValue(StockRealtimePrice|StockHoldingIntradayCandle|null $price): ?string
    {
        if ($price instanceof StockRealtimePrice) {
            return (string) $price->price;
        }

        if ($price instanceof StockHoldingIntradayCandle) {
            return (string) $price->close;
        }

        return null;
    }

    private function dashboardPriceTimestamp(StockRealtimePrice|StockHoldingIntradayCandle|null $price): ?string
    {
        if ($price instanceof StockRealtimePrice) {
            return $this->storedStockPriceTimestamp($price, 'as_of');
        }

        if ($price instanceof StockHoldingIntradayCandle) {
            return $this->storedIntradayCandleTimestamp($price);
        }

        return null;
    }

    private function dashboardPriceFetchedTimestamp(StockRealtimePrice|StockHoldingIntradayCandle|null $price): ?string
    {
        if ($price instanceof StockRealtimePrice) {
            return $this->storedStockPriceTimestamp($price, 'fetched_at');
        }

        return null;
    }

    private function dashboardPriceSourceName(StockRealtimePrice|StockHoldingIntradayCandle|null $price): ?string
    {
        return $price?->source_name;
    }

    private function dashboardPriceSourceUrl(StockRealtimePrice|StockHoldingIntradayCandle|null $price): ?string
    {
        return $price?->source_url;
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
     * @return array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>
     */
    private function intradayPricePayloadForChartRange(
        StockHolding $holding,
        ?string $chartRange,
        bool $includeIntradayCharts,
    ): array {
        if ($chartRange === '1w') {
            return $this->weeklyIntradaySamplePayload($holding);
        }

        if (! $includeIntradayCharts) {
            return [];
        }

        return $this->intradayPricePayload($holding);
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

    private function previousStoredPrice(
        StockHolding $holding,
        StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $latestStockPrice,
    ): ?string {
        if ($latestStockPrice === null) {
            return null;
        }

        if ($latestStockPrice instanceof StockHoldingIntradayCandle) {
            return $this->previousStoredIntradayCandlePrice($holding, $latestStockPrice);
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

    private function previousStoredIntradayCandlePrice(
        StockHolding $holding,
        StockHoldingIntradayCandle $latestIntradayCandle,
    ): ?string {
        $query = $holding->intradayCandles()
            ->whereKeyNot($latestIntradayCandle->id)
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->whereNotNull('close');

        if ($latestIntradayCandle->as_of !== null) {
            $query->where(function ($query) use ($latestIntradayCandle): void {
                $query
                    ->where('as_of', '<', $latestIntradayCandle->as_of)
                    ->orWhere(function ($query) use ($latestIntradayCandle): void {
                        $query
                            ->where('as_of', $latestIntradayCandle->as_of)
                            ->where('id', '<', $latestIntradayCandle->id);
                    });
            });
        }

        return $query
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->value('close');
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

        $latestTradingDate = Carbon::parse($latestTradingDay, 'UTC')
            ->setTimezone(config('app.timezone'))
            ->toDateString();

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
        $latestRealtimePrice = $recentRealtimePrices->last();
        $latestTradingDate = $latestRealtimePrice
            ? $this->storedStockPriceDateTime($latestRealtimePrice, 'as_of')
                ?->setTimezone(config('app.timezone'))
                ->toDateString()
            : null;

        if ($latestTradingDate === null) {
            return null;
        }

        $hasPreviousTradingDate = $recentRealtimePrices
            ->contains(function (StockRealtimePrice $stockPrice) use ($latestTradingDate): bool {
                $tradingDate = $this->storedStockPriceDateTime($stockPrice, 'as_of')
                    ?->setTimezone(config('app.timezone'))
                    ->toDateString();

                return $tradingDate !== null && $tradingDate < $latestTradingDate;
            });

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
            $realtimePrices = $this->realtimeIntradayPricePayload($holding, $sessionDate);
            $storedCandleCount = $this->storedIntradayPriceCount($holding, $sessionDate);

            if ($storedCandleCount === 0) {
                $this->eodhdMarketData->ensureIntradaySamples($holding);
                $storedCandleCount = $this->storedIntradayPriceCount($holding, $sessionDate);
            }

            if ($storedCandleCount >= 2) {
                return $this->intradayPricePayloadWithNewerRealtimePrices(
                    $this->storedEodhdIntradayPayload($holding, $sessionDate),
                    $holding,
                    $sessionDate,
                );
            }

            $storedIntradayPrices = $this->storedSameDayIntradayFallbackPayload($holding, $sessionDate);

            if (count($storedIntradayPrices) >= 2) {
                return $this->intradayPricePayloadWithNewerRealtimePrices(
                    $storedIntradayPrices,
                    $holding,
                    $sessionDate,
                );
            }

            return $storedCandleCount > 0
                ? $this->intradayPricePayloadWithNewerRealtimePrices(
                    $this->storedEodhdIntradayPayload($holding, $sessionDate),
                    $holding,
                    $sessionDate,
                )
                : $realtimePrices;
        }

        $latestStoredDate = $this->latestStoredIntradayDate($holding);
        $latestRealtimeDate = $this->latestRealtimeTradingDate($holding);

        if ($latestRealtimeDate !== null && ($latestStoredDate === null || $latestRealtimeDate > Carbon::parse($latestStoredDate)->toDateString())) {
            return $this->realtimeIntradayPricePayload($holding, $latestRealtimeDate);
        }

        if ($latestStoredDate === null) {
            return [];
        }

        return $this->intradayPricePayloadWithNewerRealtimePrices(
            $this->storedEodhdIntradayPayload($holding, $latestStoredDate),
            $holding,
            Carbon::parse($latestStoredDate)->toDateString(),
        );
    }

    /**
     * @return array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>
     */
    private function weeklyIntradaySamplePayload(StockHolding $holding): array
    {
        $latestDate = $this->latestWeeklyIntradaySampleDate($holding);

        if ($latestDate === null) {
            return [];
        }

        $startDate = Carbon::parse($latestDate)->subDays(7)->toDateString();
        $sampledCandles = StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->whereDate('trading_date', '>=', $startDate)
            ->whereDate('trading_date', '<=', $latestDate)
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->whereNotNull('close')
            ->orderBy('trading_date')
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'trading_date', 'close', 'currency', 'as_of', 'timestamp', 'source_name'])
            ->groupBy(fn (StockHoldingIntradayCandle $intradayCandle): string => $intradayCandle->trading_date->toDateString())
            ->flatMap(fn (Collection $intradayCandles): Collection => $this->evenlySampleRows(
                $intradayCandles,
                self::WEEKLY_INTRADAY_SAMPLES_PER_DAY,
            ))
            ->values();
        $sampledCandleDates = $sampledCandles
            ->map(fn (StockHoldingIntradayCandle $intradayCandle): string => $intradayCandle->trading_date->toDateString())
            ->unique()
            ->values();
        $sampledRealtimePrices = $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->whereDate('as_of', '>=', $startDate)
            ->whereDate('as_of', '<=', $latestDate)
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'price', 'currency', 'as_of', 'source_name', 'price_type'])
            ->groupBy(function (StockRealtimePrice $stockPrice): string {
                return $this->storedStockPriceDateTime($stockPrice, 'as_of')
                    ?->setTimezone(config('app.timezone'))
                    ->toDateString() ?? '';
            })
            ->reject(fn (Collection $stockPrices, string $tradingDate): bool => $tradingDate === ''
                || $sampledCandleDates->contains($tradingDate))
            ->flatMap(fn (Collection $stockPrices): Collection => $this->evenlySampleRows(
                $stockPrices,
                self::WEEKLY_INTRADAY_SAMPLES_PER_DAY,
            ))
            ->values();

        return $sampledCandles
            ->map(fn (StockHoldingIntradayCandle $intradayCandle): array => [
                'id' => $intradayCandle->id,
                'price' => (string) $intradayCandle->close,
                'currency' => $intradayCandle->currency,
                'as_of' => $this->storedIntradayCandleTimestamp($intradayCandle),
                'source_name' => $intradayCandle->source_name,
                'price_type' => 'intraday',
            ])
            ->merge($sampledRealtimePrices->map(fn (StockRealtimePrice $stockPrice): array => [
                'id' => $stockPrice->id,
                'price' => (string) $stockPrice->price,
                'currency' => $stockPrice->currency,
                'as_of' => $this->storedStockPriceTimestamp($stockPrice, 'as_of'),
                'source_name' => $stockPrice->source_name,
                'price_type' => $stockPrice->price_type,
            ]))
            ->filter(fn (array $price): bool => $price['as_of'] !== null && $price['price'] !== '')
            ->sortBy('as_of')
            ->values()
            ->all();
    }

    private function latestWeeklyIntradaySampleDate(StockHolding $holding): ?string
    {
        return collect([
            StockHoldingDailyPrice::query()
                ->where('stock_holding_id', $holding->id)
                ->max('trading_date'),
            $this->latestStoredIntradayDate($holding),
            $this->latestRealtimeTradingDate($holding),
        ])
            ->filter()
            ->map(fn (Carbon|string $date): string => Carbon::parse($date)->toDateString())
            ->max();
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  Collection<TKey, TValue>  $rows
     * @return Collection<int, TValue>
     */
    private function evenlySampleRows(Collection $rows, int $sampleCount): Collection
    {
        $values = $rows->values();
        $rowCount = $values->count();

        if ($rowCount <= $sampleCount) {
            return $values;
        }

        $lastIndex = $rowCount - 1;

        return collect(range(0, $sampleCount - 1))
            ->map(fn (int $index): mixed => $values->get((int) round(($index / ($sampleCount - 1)) * $lastIndex)))
            ->filter()
            ->unique(fn (mixed $row): mixed => $row->id ?? spl_object_id($row))
            ->values();
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
     * @param  array<int, array{title: string, trading_date: string, interval: string, rows: array<int, array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}>}>  $intradayDays
     * @return array<int, array{title: string, trading_date: string, interval: string, rows: array<int, array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}>}>
     */
    private function intradayDaysWithLatestRealtimePrices(StockHolding $holding, array $intradayDays): array
    {
        $latestRealtimeDate = $this->latestRealtimeTradingDate($holding);

        if ($latestRealtimeDate === null) {
            return $intradayDays;
        }

        $firstIntradayDate = $intradayDays[0]['trading_date'] ?? null;

        $realtimeRows = $this->realtimeIntradayCandleRows($holding, $latestRealtimeDate);

        if ($realtimeRows === []) {
            return $intradayDays;
        }

        $matchingDayIndex = collect($intradayDays)->search(
            fn (array $day): bool => $day['trading_date'] === $latestRealtimeDate,
        );

        if ($matchingDayIndex !== false) {
            $intradayDays[$matchingDayIndex] = $this->intradayDayWithNewerRealtimeRows(
                $intradayDays[$matchingDayIndex],
                $realtimeRows,
            );

            return $intradayDays;
        }

        if ($firstIntradayDate !== null && $latestRealtimeDate < $firstIntradayDate) {
            return $intradayDays;
        }

        return collect([
            [
                'title' => 'Intraday '.Carbon::parse($latestRealtimeDate)->format('d.m.Y'),
                'trading_date' => $latestRealtimeDate,
                'interval' => 'realtime',
                'rows' => $realtimeRows,
            ],
            ...$intradayDays,
        ])
            ->unique(fn (array $day): string => $day['trading_date'])
            ->take(7)
            ->values()
            ->all();
    }

    /**
     * @param  array{title: string, trading_date: string, interval: string, rows: array<int, array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}>}  $intradayDay
     * @param  array<int, array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}>  $realtimeRows
     * @return array{title: string, trading_date: string, interval: string, rows: array<int, array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}>}
     */
    private function intradayDayWithNewerRealtimeRows(array $intradayDay, array $realtimeRows): array
    {
        $latestStoredTimestamp = collect($intradayDay['rows'])
            ->map(fn (array $row): ?int => $this->intradayRowTimestamp($row))
            ->filter()
            ->max();

        $newerRealtimeRows = collect($realtimeRows)
            ->filter(fn (array $row): bool => $latestStoredTimestamp === null || $this->intradayRowTimestamp($row) > $latestStoredTimestamp)
            ->values()
            ->all();

        if ($newerRealtimeRows === []) {
            return $intradayDay;
        }

        return [
            ...$intradayDay,
            'rows' => collect([
                ...$intradayDay['rows'],
                ...$newerRealtimeRows,
            ])
                ->sortBy(fn (array $row): int => $this->intradayRowTimestamp($row) ?? PHP_INT_MAX)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>
     */
    private function realtimeIntradayPricePayload(StockHolding $holding, string $tradingDate): array
    {
        $realtimePrices = $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->whereDate('as_of', $tradingDate)
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['id', 'price', 'currency', 'as_of', 'source_name', 'price_type']);
        $previousRealtimePrice = $this->previousTradingDayLastRealtimePrice($holding, $realtimePrices);

        return $realtimePrices
            ->when($previousRealtimePrice !== null, fn ($prices) => $prices->prepend($previousRealtimePrice))
            ->map(fn (StockRealtimePrice $stockPrice): array => [
                'id' => $stockPrice->id,
                'price' => (string) $stockPrice->price,
                'currency' => $stockPrice->currency,
                'as_of' => $this->storedStockPriceTimestamp($stockPrice, 'as_of'),
                'source_name' => $stockPrice->source_name,
                'price_type' => $stockPrice->price_type,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>  $intradayPrices
     * @return array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>
     */
    private function intradayPricePayloadWithNewerRealtimePrices(
        array $intradayPrices,
        StockHolding $holding,
        string $tradingDate,
    ): array {
        $latestIntradayTime = collect($intradayPrices)
            ->pluck('as_of')
            ->filter()
            ->map(fn (string $asOf): int => Carbon::parse($asOf)->timestamp)
            ->max();
        $newerRealtimePrices = collect($this->realtimeIntradayPricePayload($holding, $tradingDate))
            ->filter(fn (array $price): bool => $price['as_of'] !== null)
            ->filter(fn (array $price): bool => $latestIntradayTime === null || Carbon::parse($price['as_of'])->timestamp > $latestIntradayTime)
            ->values()
            ->all();

        if ($newerRealtimePrices === []) {
            return $intradayPrices;
        }

        return [
            ...$intradayPrices,
            ...$newerRealtimePrices,
        ];
    }

    /**
     * @return array<int, array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}>
     */
    private function realtimeIntradayCandleRows(StockHolding $holding, string $tradingDate): array
    {
        return $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->whereDate('as_of', $tradingDate)
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['price', 'as_of'])
            ->map(function (StockRealtimePrice $stockPrice): array {
                $asOf = $this->storedStockPriceDateTime($stockPrice, 'as_of')?->utc();

                return [
                    'timestamp' => $asOf?->timestamp,
                    'gmtoffset' => 0,
                    'datetime' => $asOf?->toDateTimeString(),
                    'open' => null,
                    'high' => null,
                    'low' => null,
                    'close' => (string) $stockPrice->price,
                    'volume' => null,
                ];
            })
            ->all();
    }

    /**
     * @param  array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}  $row
     */
    private function intradayRowTimestamp(array $row): ?int
    {
        if (($row['timestamp'] ?? null) !== null && (int) $row['timestamp'] > 0) {
            return (int) $row['timestamp'];
        }

        if (! is_string($row['datetime'] ?? null) || trim($row['datetime']) === '') {
            return null;
        }

        return Carbon::parse($row['datetime'], 'UTC')->timestamp;
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
            return $this->dailyPricePayloadWithLatestRealtimePrice(
                $this->dailyPricePayloadFromIntradayCandles($holding),
                $holding,
            );
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

        return $this->dailyPricePayloadWithLatestRealtimePrice([
            ...$dailyPrices,
            ...$newerIntradayPrices,
        ], $holding);
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

    /**
     * @param  array<int, array{trading_date: string, price: string, volume: ?int, currency: ?string}>  $dailyPrices
     * @return array<int, array{trading_date: string, price: string, volume: ?int, currency: ?string}>
     */
    private function dailyPricePayloadWithLatestRealtimePrice(array $dailyPrices, StockHolding $holding): array
    {
        $latestRealtimePrice = $this->latestRealtimeDailyPricePayload($holding);

        if ($latestRealtimePrice === null) {
            return $dailyPrices;
        }

        $latestDailyDate = collect($dailyPrices)
            ->pluck('trading_date')
            ->filter()
            ->max();

        if ($latestDailyDate !== null && $latestRealtimePrice['trading_date'] <= $latestDailyDate) {
            return $dailyPrices;
        }

        return [
            ...$dailyPrices,
            $latestRealtimePrice,
        ];
    }

    /**
     * @return array{trading_date: string, price: string, volume: ?int, currency: ?string}|null
     */
    private function latestRealtimeDailyPricePayload(StockHolding $holding): ?array
    {
        $latestTradingDate = $this->latestRealtimeTradingDate($holding);

        if ($latestTradingDate === null) {
            return null;
        }

        $latestRealtimePrice = $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->whereDate('as_of', $latestTradingDate)
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->first(['price', 'currency', 'as_of']);

        if ($latestRealtimePrice === null) {
            return null;
        }

        return [
            'trading_date' => $latestTradingDate,
            'price' => (string) $latestRealtimePrice->price,
            'volume' => null,
            'currency' => $latestRealtimePrice->currency,
        ];
    }

    private function latestRealtimeTradingDate(StockHolding $holding): ?string
    {
        $latestTradingDay = $holding->realtimePrices()
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->latest('as_of')
            ->value('as_of');

        return $latestTradingDay === null
            ? null
            : Carbon::parse($latestTradingDay, 'UTC')
                ->setTimezone(config('app.timezone'))
                ->toDateString();
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

    private function endOfDayDate(StockPrice $stockPrice): ?string
    {
        return $this->storedStockPriceDateTime($stockPrice, 'as_of')
            ?->setTimezone(config('app.timezone'))
            ->toDateString();
    }

    private function storedStockPriceTimestamp(StockPrice|StockRealtimePrice $stockPrice, string $column): ?string
    {
        return $this->storedStockPriceDateTime($stockPrice, $column)?->toIso8601String();
    }

    private function storedStockPriceDateTime(StockPrice|StockRealtimePrice $stockPrice, string $column): ?Carbon
    {
        $value = $stockPrice->getRawOriginal($column);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC');
    }

    private function storedIntradayCandleDateTime(StockHoldingIntradayCandle $intradayCandle): ?Carbon
    {
        $value = $intradayCandle->getRawOriginal('as_of');

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC');
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
