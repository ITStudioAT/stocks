<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Services\EodhdApiUsage;
use App\Services\IndexPriceRefreshSettings;
use App\Services\PriceRefreshScheduler;
use App\Services\UiPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminDepotController extends Controller
{
    public function index(): JsonResponse
    {
        $depots = Depot::query()
            ->orderBy('name')
            ->paginate(10)
            ->through(fn (Depot $depot): array => $this->depotPayload($depot));

        return response()->json([
            'depots' => $depots->items(),
            'meta' => [
                'current_page' => $depots->currentPage(),
                'last_page' => $depots->lastPage(),
                'per_page' => $depots->perPage(),
                'total' => $depots->total(),
                'from' => $depots->firstItem(),
                'to' => $depots->lastItem(),
            ],
        ]);
    }

    public function active(Request $request, PriceRefreshScheduler $priceRefreshScheduler, IndexPriceRefreshSettings $indexPriceRefreshSettings, EodhdApiUsage $eodhdApiUsage, UiPreferences $uiPreferences): JsonResponse
    {
        $depot = Depot::query()
            ->where('is_active', true)
            ->first();

        $indexPriceRefreshSettings->dispatchOverdueRefreshes();

        return response()->json([
            'depot' => $depot ? $this->depotPayload($depot) : null,
            'app_version' => config('stocks.version'),
            'price_refresh_settings' => $priceRefreshScheduler->payload(),
            'index_price_refresh_settings' => $indexPriceRefreshSettings->payload(),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
            'ui_preferences' => $uiPreferences->payload($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedDepotData($request);

        $depot = DB::transaction(function () use ($validated): Depot {
            $shouldActivateDepot = (bool) ($validated['is_active'] ?? false)
                || ! Depot::query()->where('is_active', true)->exists();

            if ($shouldActivateDepot) {
                Depot::query()->update(['is_active' => false]);
            }

            return Depot::create([
                'name' => $validated['name'],
                'account_balance' => $validated['account_balance'],
                'is_active' => $shouldActivateDepot,
            ]);
        });

        return response()->json([
            'message' => 'Depot created.',
            'depot' => $this->depotPayload($depot),
        ], 201);
    }

    public function update(Request $request, Depot $depot): JsonResponse
    {
        $validated = $this->validatedDepotData($request, $depot);

        DB::transaction(function () use ($depot, $validated): void {
            $shouldActivateDepot = (bool) ($validated['is_active'] ?? false);

            if ($shouldActivateDepot) {
                Depot::query()->whereKeyNot($depot->id)->update(['is_active' => false]);
            }

            $depot->update([
                'name' => $validated['name'],
                'account_balance' => $validated['account_balance'],
                'is_active' => $shouldActivateDepot || $depot->is_active,
            ]);
        });

        return response()->json([
            'message' => 'Depot updated.',
            'depot' => $this->depotPayload($depot->refresh()),
        ]);
    }

    public function activate(Depot $depot): JsonResponse
    {
        DB::transaction(function () use ($depot): void {
            Depot::query()->whereKeyNot($depot->id)->update(['is_active' => false]);

            $depot->update([
                'is_active' => true,
            ]);
        });

        return response()->json([
            'message' => 'Depot activated.',
            'depot' => $this->depotPayload($depot->refresh()),
        ]);
    }

    /**
     * @return array{id: int, name: string, account_balance: string, current_account_balance: string, is_active: bool, created_at: ?string, updated_at: ?string}
     */
    private function depotPayload(Depot $depot): array
    {
        return [
            'id' => $depot->id,
            'name' => $depot->name,
            'account_balance' => $depot->account_balance,
            'current_account_balance' => $this->currentAccountBalance($depot),
            'is_active' => $depot->is_active,
            'created_at' => $depot->created_at?->toIso8601String(),
            'updated_at' => $depot->updated_at?->toIso8601String(),
        ];
    }

    private function currentAccountBalance(Depot $depot): string
    {
        $positionPiecesByHoldingId = DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereNotNull('stock_holding_id')
            ->whereIn('type', DepotTransaction::StockTypes)
            ->get(['stock_holding_id', 'type', 'pieces'])
            ->groupBy('stock_holding_id')
            ->map(function ($transactions): float {
                return $transactions->reduce(function (float $sum, DepotTransaction $transaction): float {
                    $pieces = (float) $transaction->pieces;

                    return $transaction->type === 'buy'
                        ? $sum + $pieces
                        : $sum - $pieces;
                }, 0.0);
            })
            ->filter(fn (float $pieces): bool => $pieces > 0);

        if ($positionPiecesByHoldingId->isEmpty()) {
            return number_format((float) $depot->account_balance, 2, '.', '');
        }

        $latestPricesByHoldingId = StockHolding::query()
            ->with('latestStockPrice')
            ->whereKey($positionPiecesByHoldingId->keys()->all())
            ->get()
            ->mapWithKeys(fn (StockHolding $holding): array => [
                $holding->id => $holding->latestStockPrice?->price ?? $holding->latest_price,
            ]);

        $stockBalance = $positionPiecesByHoldingId->reduce(
            fn (float $sum, float $pieces, int $holdingId): float => $sum + ($pieces * (float) ($latestPricesByHoldingId->get($holdingId) ?? 0)),
            0.0,
        );

        return number_format(((float) $depot->account_balance) + $stockBalance, 2, '.', '');
    }

    /**
     * @return array{name: string, account_balance: numeric-string, is_active?: bool}
     */
    private function validatedDepotData(Request $request, ?Depot $depot = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Depot::class, 'name')->ignore($depot),
            ],
            'account_balance' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
