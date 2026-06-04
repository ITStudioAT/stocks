<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\DepotTransactionBooker;
use App\Services\EodhdApiUsage;
use App\Services\EodhdMarketData;
use App\Services\PriceRefreshScheduler;
use App\Services\StockPriceCatalog;
use App\Services\StockPriceFreshness;
use App\Services\TradingSessionPriceResolver;
use App\Services\WatchlistPdfReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AdminDepotHoldingController extends Controller
{
    public function __construct(
        private StockPriceFreshness $stockPriceFreshness,
        private PriceRefreshScheduler $priceRefreshScheduler,
        private TradingSessionPriceResolver $tradingSessionPriceResolver,
        private StockPriceCatalog $stockPriceCatalog,
        private WatchlistPdfReport $watchlistPdfReport,
        private DepotTransactionBooker $depotTransactionBooker,
        private EodhdMarketData $eodhdMarketData,
        private EodhdApiUsage $eodhdApiUsage,
    ) {}

    public function index(): JsonResponse
    {
        $activeDepot = $this->activeDepot();
        $holdings = StockHolding::query()
            ->with('latestStockPrice')
            ->orderBy('name')
            ->orderBy('isin')
            ->paginate(10)
            ->through(fn (StockHolding $holding): array => $this->holdingPayload($holding, $activeDepot));

        return response()->json([
            'depot' => $activeDepot ? $this->depotPayload($activeDepot) : null,
            'price_refresh_settings' => $this->priceRefreshScheduler->payload(),
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
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

        return response()->json([
            'exchange_trading_times' => $this->eodhdMarketData->exchangeTradingTimes($holdings),
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
        $message = trans_choice('{0} No stock prices queued for refresh.|{1} 1 stock price queued for refresh.|[2,*] :count stock prices queued for refresh.', $dispatchedRefresh['total_holdings']);

        if ($progress === null) {
            return response()->json([
                'message' => $message,
                'refresh' => null,
                'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
            ], 202);
        }

        return response()->json([
            'message' => $message,
            'refresh' => $this->refreshPayload($progress),
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
        ], 202);
    }

    public function refreshPriceStatus(string $refreshId, DepotHoldingPriceRefreshProgress $refreshProgress): JsonResponse
    {
        $progress = $refreshProgress->get($refreshId);

        if ($progress === null) {
            return response()->json([
                'message' => 'Stock price refresh not found.',
            ], 404);
        }

        return response()->json([
            'message' => $progress['message'],
            'refresh' => $this->refreshPayload($progress),
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
        ]);
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
     * @return array{id: int, symbol: ?string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_price: ?string, start_price: ?string, end_price: ?string, start_price_24: ?string, start_price_48: ?string, historical_prices_fetching: bool, position_pieces: string, latest_price_trend: ?string, latest_price_change_pct: ?string, latest_price_tick_trend: ?string, latest_price_status: string, price_status: ?string, latest_price_fetched_at: ?string, latest_price_source: ?string, latest_price_source_url: ?string, latest_price_as_of: ?string, trading_times: ?string, venue: ?string, price_type: ?string, price_spread_pct: ?string, recent_prices: array<int, array{id: int, price: string, currency: ?string, as_of: ?string, source_name: ?string, price_type: ?string}>, validation_errors: array<int, string>, created_at: ?string}
     */
    private function holdingPayload(StockHolding $holding, ?Depot $activeDepot): array
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
        $latestPriceReference = $sessionPrices['start_price'];

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
            'start_price_24' => $sessionPrices['start_price_24'],
            'start_price_48' => $sessionPrices['start_price_48'],
            'historical_prices_fetching' => $sessionPrices['historical_prices_fetching'],
            'position_pieces' => $activeDepot
                ? $this->depotTransactionBooker->positionPieces($activeDepot, $holding)
                : '0.00000000',
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
