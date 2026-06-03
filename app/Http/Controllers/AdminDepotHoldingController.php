<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshDepotHoldingPrices;
use App\Models\Depot;
use App\Models\StockHolding;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\StockPriceFreshness;
use App\Services\StockPriceLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminDepotHoldingController extends Controller
{
    public function __construct(
        private StockPriceFreshness $stockPriceFreshness,
    ) {}

    public function index(): JsonResponse
    {
        $depot = $this->activeDepot();

        $holdings = $depot->stockHoldings()
            ->orderBy('name')
            ->orderBy('isin')
            ->paginate(10)
            ->through(fn (StockHolding $holding): array => $this->holdingPayload($holding));

        return response()->json([
            'depot' => $this->depotPayload($depot),
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

    public function store(Request $request, StockPriceLookupService $stockPriceLookup): JsonResponse
    {
        $depot = $this->activeDepot();
        $validated = $this->validatedHoldingData($request, $depot);
        $latestPriceData = $stockPriceLookup->latestPrice($validated);

        $holding = $depot->stockHoldings()->create([
            'symbol' => $validated['symbol'],
            'name' => $validated['name'] ?? null,
            'isin' => $validated['isin'] ?? null,
            'wkn' => $validated['wkn'] ?? null,
            'exchange' => $validated['exchange'] ?? null,
            'mic_code' => $validated['mic_code'] ?? null,
            'instrument_type' => $validated['instrument_type'] ?? null,
            'country' => $validated['country'] ?? null,
            'currency' => $latestPriceData['currency'] ?? $validated['currency'] ?? null,
            'latest_price' => $latestPriceData['price'],
            'latest_price_fetched_at' => $latestPriceData['fetched_at'],
            'latest_price_source' => $latestPriceData['source'],
            'latest_price_source_url' => $latestPriceData['source_url'],
            'latest_price_as_of' => $latestPriceData['as_of'],
            'trading_times' => $latestPriceData['trading_times'],
        ]);

        return response()->json([
            'message' => 'Stock added.',
            'holding' => $this->holdingPayload($holding),
        ], 201);
    }

    public function refreshPrices(DepotHoldingPriceRefreshProgress $refreshProgress): JsonResponse
    {
        $depot = $this->activeDepot();
        $refreshId = (string) Str::uuid();
        $total = $depot->stockHoldings()->count();
        $progress = $refreshProgress->start($refreshId, $depot->id, $total);

        if ($total > 0) {
            RefreshDepotHoldingPrices::dispatch($depot->id, $refreshId);
            $progress = $refreshProgress->get($refreshId) ?? $progress;
        }

        return response()->json([
            'message' => $progress['message'],
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
     * @return array{id: int, symbol: ?string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, latest_price: ?string, latest_price_status: string, latest_price_fetched_at: ?string, latest_price_source: ?string, latest_price_source_url: ?string, latest_price_as_of: ?string, trading_times: ?string, created_at: ?string}
     */
    private function holdingPayload(StockHolding $holding): array
    {
        $latestPriceStatus = $this->latestPriceStatus($holding);

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
            'latest_price' => $latestPriceStatus === 'fresh' ? $holding->latest_price : null,
            'latest_price_status' => $latestPriceStatus,
            'latest_price_fetched_at' => $holding->latest_price_fetched_at?->toIso8601String(),
            'latest_price_source' => $holding->latest_price_source,
            'latest_price_source_url' => $holding->latest_price_source_url,
            'latest_price_as_of' => in_array($latestPriceStatus, ['fresh', 'stale'], true) ? $holding->latest_price_as_of : null,
            'trading_times' => $holding->trading_times,
            'created_at' => $holding->created_at?->toIso8601String(),
        ];
    }

    private function latestPriceStatus(StockHolding $holding): string
    {
        if ($holding->latest_price === null) {
            return $holding->latest_price_fetched_at === null ? 'missing' : 'unavailable';
        }

        if ($this->stockPriceFreshness->isFresh($holding->latest_price_as_of, $holding->trading_times)) {
            return 'fresh';
        }

        return 'stale';
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
