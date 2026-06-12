<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Services\DepotTransactionBooker;
use App\Services\UiPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminDepotTransactionController extends Controller
{
    public function index(UiPreferences $uiPreferences): JsonResponse
    {
        $depot = $this->activeDepot();

        if (! $depot) {
            return response()->json([
                'depot_holdings' => [],
                'transactions' => [],
                'ui_preferences' => $uiPreferences->payload(),
            ]);
        }

        return response()->json([
            'depot_holdings' => $this->depotHoldingPayloads($depot),
            'transactions' => DepotTransaction::query()
                ->with('stockHolding')
                ->where('depot_id', $depot->id)
                ->latest('booked_at')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (DepotTransaction $transaction): array => $this->transactionPayload($transaction))
                ->all(),
            'ui_preferences' => $uiPreferences->payload(),
        ]);
    }

    public function storeCash(Request $request, DepotTransactionBooker $booker): JsonResponse
    {
        $depot = $this->activeDepotOrFail();
        $validated = $request->validate([
            'type' => ['required', Rule::in(['deposit', 'withdrawal'])],
            'total_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'booked_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $bookedAt = isset($validated['booked_at'])
            ? $request->date('booked_at')->startOfDay()
            : null;

        $transaction = $booker->bookCash(
            depot: $depot,
            type: $validated['type'],
            totalAmount: (string) $validated['total_amount'],
            note: $validated['note'] ?? null,
            bookedAt: $bookedAt,
        );

        return response()->json([
            'message' => 'Cash transaction booked.',
            'depot' => $this->depotPayload($transaction->depot->refresh()),
            'transaction' => $this->transactionPayload($transaction->load('stockHolding')),
        ], 201);
    }

    public function storeStock(Request $request, DepotTransactionBooker $booker): JsonResponse
    {
        $depot = $this->activeDepotOrFail();
        $validated = $request->validate([
            'type' => ['required', Rule::in(['buy', 'sell'])],
            'stock_holding_id' => ['required', Rule::exists(StockHolding::class, 'id')],
            'pieces' => ['required', 'numeric', 'min:0.00000001', 'max:999999999999.99999999'],
            'total_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'booked_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $holding = StockHolding::query()->findOrFail($validated['stock_holding_id']);
        $bookedAt = isset($validated['booked_at'])
            ? $request->date('booked_at')->startOfDay()
            : null;

        $transaction = $booker->bookStock(
            depot: $depot,
            holding: $holding,
            type: $validated['type'],
            pieces: (string) $validated['pieces'],
            totalAmount: (string) $validated['total_amount'],
            note: $validated['note'] ?? null,
            bookedAt: $bookedAt,
        );

        return response()->json([
            'message' => 'Stock transaction booked.',
            'depot' => $this->depotPayload($transaction->depot->refresh()),
            'depot_holdings' => $this->depotHoldingPayloads($depot),
            'transaction' => $this->transactionPayload($transaction->load('stockHolding')),
        ], 201);
    }

    private function activeDepotOrFail(): Depot
    {
        $depot = $this->activeDepot();

        if (! $depot) {
            throw ValidationException::withMessages([
                'depot' => 'No active depot available.',
            ]);
        }

        return $depot;
    }

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
     * @return array<int, array{id: int, symbol: ?string, name: ?string, currency: ?string, latest_price: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>
     */
    private function depotHoldingPayloads(Depot $depot): array
    {
        $transactionsByHoldingId = DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereNotNull('stock_holding_id')
            ->whereIn('type', ['buy', 'sell'])
            ->orderBy('booked_at')
            ->orderBy('id')
            ->get(['id', 'stock_holding_id', 'type', 'pieces', 'total_amount', 'booked_at'])
            ->groupBy('stock_holding_id');

        $positionPiecesByHoldingId = $transactionsByHoldingId
            ->map(function ($transactions): string {
                $pieces = $transactions->reduce(function (float $sum, DepotTransaction $transaction): float {
                    $transactionPieces = (float) $transaction->pieces;

                    return $transaction->type === 'buy'
                        ? $sum + $transactionPieces
                        : $sum - $transactionPieces;
                }, 0.0);

                return number_format($pieces, 8, '.', '');
            })
            ->filter(fn (string $pieces): bool => (float) $pieces > 0);

        if ($positionPiecesByHoldingId->isEmpty()) {
            return [];
        }

        $yearStartPriceByHoldingId = $this->yearStartPriceByHoldingId($transactionsByHoldingId);

        return StockHolding::query()
            ->with('latestStockPrice')
            ->whereKey($positionPiecesByHoldingId->keys()->all())
            ->orderBy('name')
            ->get()
            ->map(fn (StockHolding $holding): array => $this->depotHoldingPayload(
                holding: $holding,
                positionPieces: $positionPiecesByHoldingId->get($holding->id, '0.00000000'),
                yearStartPrice: $yearStartPriceByHoldingId->get($holding->id),
            ))
            ->all();
    }

    /**
     * @param  Collection<int, Collection<int, DepotTransaction>>  $transactionsByHoldingId
     * @return Collection<int, ?string>
     */
    private function yearStartPriceByHoldingId(Collection $transactionsByHoldingId): Collection
    {
        $yearStart = now()->startOfYear();

        return $transactionsByHoldingId
            ->map(function ($transactions) use ($yearStart): ?string {
                $openLots = collect($this->openBuyLots($transactions))
                    ->filter(fn (array $lot): bool => $lot['booked_at']?->greaterThanOrEqualTo($yearStart) ?? false);
                $pieces = $openLots->sum(fn (array $lot): float => $lot['pieces']);

                if ($pieces <= 0) {
                    return null;
                }

                $totalAmount = $openLots->sum(fn (array $lot): float => $lot['total_amount']);

                return number_format($totalAmount / $pieces, 8, '.', '');
            });
    }

    /**
     * @param  Collection<int, DepotTransaction>  $transactions
     * @return array<int, array{pieces: float, total_amount: float, booked_at: ?Carbon}>
     */
    private function openBuyLots(Collection $transactions): array
    {
        $lots = [];

        foreach ($transactions as $transaction) {
            $transactionPieces = (float) $transaction->pieces;

            if ($transactionPieces <= 0) {
                continue;
            }

            if ($transaction->type === 'buy') {
                $lots[] = [
                    'pieces' => $transactionPieces,
                    'total_amount' => (float) $transaction->total_amount,
                    'booked_at' => $transaction->booked_at,
                ];

                continue;
            }

            $piecesToSell = $transactionPieces;

            foreach ($lots as $index => $lot) {
                if ($piecesToSell <= 0) {
                    break;
                }

                $consumedPieces = min($lot['pieces'], $piecesToSell);
                $consumedRatio = $consumedPieces / $lot['pieces'];

                $lots[$index]['pieces'] = $lot['pieces'] - $consumedPieces;
                $lots[$index]['total_amount'] = $lot['total_amount'] - ($lot['total_amount'] * $consumedRatio);
                $piecesToSell -= $consumedPieces;
            }

            $lots = array_values(array_filter(
                $lots,
                fn (array $lot): bool => $lot['pieces'] > 0.000000004,
            ));
        }

        return $lots;
    }

    /**
     * @return array{id: int, symbol: ?string, name: ?string, currency: ?string, latest_price: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}
     */
    private function depotHoldingPayload(StockHolding $holding, string $positionPieces, ?string $yearStartPrice): array
    {
        $latestStockPrice = $holding->latestStockPrice;
        $latestPrice = $latestStockPrice?->price ?? $holding->latest_price;

        return [
            'id' => $holding->id,
            'symbol' => $holding->symbol,
            'name' => $holding->name,
            'currency' => $latestStockPrice?->currency ?? $holding->currency,
            'latest_price' => $latestPrice,
            'flatex_price' => $holding->flatex_price,
            'year_start_price' => $yearStartPrice,
            'latest_price_fetched_at' => $latestStockPrice?->fetched_at?->toIso8601String() ?? $holding->latest_price_fetched_at?->toIso8601String(),
            'latest_price_status' => $this->latestPriceStatus($holding, $latestPrice),
            'position_pieces' => $positionPieces,
        ];
    }

    private function latestPriceStatus(StockHolding $holding, ?string $latestPrice): string
    {
        $storedPriceStatus = $this->storedPriceStatus($holding->latestStockPrice);

        if ($storedPriceStatus !== null) {
            return $storedPriceStatus;
        }

        if (in_array($holding->price_status, ['realtime', 'fresh', 'delayed', 'closed_market', 'suspicious', 'unavailable_now', 'stale'], true)) {
            return $holding->price_status;
        }

        if ($latestPrice === null) {
            return $holding->latest_price_fetched_at === null ? 'missing' : 'unavailable';
        }

        return 'fresh';
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

    /**
     * @return array{id: int, type: string, stock_holding_id: ?int, stock_label: ?string, pieces: ?string, total_amount: string, unit_price: ?string, cash_delta: string, balance_after: string, booked_at: ?string, note: ?string}
     */
    private function transactionPayload(DepotTransaction $transaction): array
    {
        $holding = $transaction->stockHolding;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'stock_holding_id' => $transaction->stock_holding_id,
            'stock_label' => $holding?->name ?? $holding?->symbol,
            'pieces' => $transaction->pieces,
            'total_amount' => $transaction->total_amount,
            'unit_price' => $transaction->unit_price,
            'cash_delta' => $transaction->cash_delta,
            'balance_after' => $transaction->balance_after,
            'booked_at' => $transaction->booked_at?->toIso8601String(),
            'note' => $transaction->note,
        ];
    }
}
