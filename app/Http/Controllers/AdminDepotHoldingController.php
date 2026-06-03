<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\StockHolding;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\PriceRefreshScheduler;
use App\Services\StockPriceFreshness;
use App\Services\WebMarketData\WebMarketDataOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminDepotHoldingController extends Controller
{
    public function __construct(
        private StockPriceFreshness $stockPriceFreshness,
        private PriceRefreshScheduler $priceRefreshScheduler,
    ) {}

    public function index(): JsonResponse
    {
        $depot = $this->activeDepot();

        $holdings = $depot->stockHoldings()
            ->with('latestQuote')
            ->orderBy('name')
            ->orderBy('isin')
            ->paginate(10)
            ->through(fn (StockHolding $holding): array => $this->holdingPayload($holding));

        return response()->json([
            'depot' => $this->depotPayload($depot),
            'price_refresh_settings' => $this->priceRefreshScheduler->payload(),
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

    public function store(Request $request, WebMarketDataOrchestrator $marketData): JsonResponse
    {
        $depot = $this->activeDepot();
        $validated = $this->validatedHoldingData($request, $depot);

        $holding = $depot->stockHoldings()->create([
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

        $marketData->resolve($holding);
        $holding->refresh();

        return response()->json([
            'message' => 'Stock added.',
            'holding' => $this->holdingPayload($holding),
        ], 201);
    }

    public function refreshPrices(): JsonResponse
    {
        $depot = $this->activeDepot();
        $dispatchedRefresh = $this->priceRefreshScheduler->dispatchAllDepots($depot);
        $progress = $dispatchedRefresh['progress'];
        $message = trans_choice('{0} No stock prices queued for refresh.|{1} 1 stock price queued for refresh.|[2,*] :count stock prices queued for refresh.', $dispatchedRefresh['total_holdings']);

        if ($progress === null) {
            return response()->json([
                'message' => $message,
                'refresh' => null,
            ], 202);
        }

        return response()->json([
            'message' => $message,
            'refresh' => $this->refreshPayload($progress),
        ], 202);
    }

    public function refreshPriceStatus(string $refreshId, DepotHoldingPriceRefreshProgress $refreshProgress): JsonResponse
    {
        $depot = $this->activeDepot();
        $progress = $refreshProgress->get($refreshId);

        if ($progress === null || $progress['depot_id'] !== $depot->id) {
            return response()->json([
                'message' => 'Stock price refresh not found.',
            ], 404);
        }

        return response()->json([
            'message' => $progress['message'],
            'refresh' => $this->refreshPayload($progress),
        ]);
    }

    public function destroy(StockHolding $holding): JsonResponse
    {
        $depot = $this->activeDepot();

        if ($holding->depot_id !== $depot->id) {
            throw ValidationException::withMessages([
                'holding' => 'This stock does not belong to the active depot.',
            ]);
        }

        $holding->delete();

        return response()->json([
            'message' => 'Stock deleted.',
        ]);
    }

    private function activeDepot(): Depot
    {
        $depot = Depot::query()
            ->where('is_active', true)
            ->first();

        if ($depot) {
            return $depot;
        }

        throw ValidationException::withMessages([
            'depot' => 'Activate a depot before adding stocks.',
        ]);
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
     * @return array{id: int, symbol: ?string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_price: ?string, latest_price_status: string, price_status: ?string, latest_price_fetched_at: ?string, latest_price_source: ?string, latest_price_source_url: ?string, latest_price_as_of: ?string, trading_times: ?string, venue: ?string, price_type: ?string, price_spread_pct: ?string, validation_errors: array<int, string>, created_at: ?string}
     */
    private function holdingPayload(StockHolding $holding): array
    {
        $latestPriceStatus = $this->latestPriceStatus($holding);
        $hasCurrentPrice = in_array($latestPriceStatus, ['realtime', 'fresh', 'delayed', 'closed_market', 'suspicious', 'unavailable_now'], true);

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
            'latest_price' => $hasCurrentPrice ? $holding->latest_price : null,
            'latest_price_status' => $latestPriceStatus,
            'price_status' => $holding->price_status,
            'latest_price_fetched_at' => $holding->latest_price_fetched_at?->toIso8601String(),
            'latest_price_source' => $holding->latest_price_source,
            'latest_price_source_url' => $holding->latest_price_source_url,
            'latest_price_as_of' => $this->sourceDateTimePayload($holding->latest_price_as_of, $hasCurrentPrice),
            'trading_times' => $holding->trading_times,
            'venue' => $hasCurrentPrice ? $holding->latestQuote?->venue : null,
            'price_type' => $hasCurrentPrice ? $holding->latest_price_type : null,
            'price_spread_pct' => $hasCurrentPrice ? $holding->price_spread_pct : null,
            'validation_errors' => $hasCurrentPrice ? $holding->latestQuote?->validation_errors ?? [] : [],
            'created_at' => $holding->created_at?->toIso8601String(),
        ];
    }

    private function latestPriceStatus(StockHolding $holding): string
    {
        if (in_array($holding->price_status, ['realtime', 'fresh', 'delayed', 'closed_market', 'suspicious', 'unavailable_now', 'stale'], true)) {
            return $holding->price_status;
        }

        if ($holding->latest_price === null) {
            return $holding->latest_price_fetched_at === null ? 'missing' : 'unavailable';
        }

        if ($this->stockPriceFreshness->isFresh($holding->latest_price_as_of, $holding->trading_times)) {
            return 'fresh';
        }

        return 'stale';
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

    /**
     * @return array{symbol: string, name?: string, isin?: string, wkn?: string, exchange?: string, mic_code?: string, instrument_type?: string, country?: string, currency?: string}
     */
    private function validatedHoldingData(Request $request, Depot $depot): array
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
                Rule::unique(StockHolding::class, 'isin')->where('depot_id', $depot->id),
            ],
            'wkn' => [
                'nullable',
                'string',
                'size:6',
                Rule::unique(StockHolding::class, 'wkn')->where('depot_id', $depot->id),
            ],
            'exchange' => ['nullable', 'string', 'max:255'],
            'mic_code' => ['nullable', 'string', 'max:32'],
            'instrument_type' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:8'],
        ]);
    }

    /**
     * @param  array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}  $progress
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
