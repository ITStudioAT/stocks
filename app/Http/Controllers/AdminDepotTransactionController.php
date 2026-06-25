<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
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
    public function index(Request $request, UiPreferences $uiPreferences): JsonResponse
    {
        $depot = $this->activeDepot();

        if (! $depot) {
            return response()->json([
                'depot_holdings' => [],
                'depot_valuations' => [],
                'depot_performance_series' => [],
                'transactions' => [],
                'ui_preferences' => $uiPreferences->payload($request->user()),
            ]);
        }

        $depotHoldings = $this->depotHoldingPayloads($depot);

        return response()->json([
            'depot_holdings' => $depotHoldings,
            'depot_valuations' => $this->depotValuationPayloads($depot, $depotHoldings),
            'depot_performance_series' => $this->depotPerformancePayloads($depot, $depotHoldings),
            'transactions' => $this->transactionPayloads($depot),
            'ui_preferences' => $uiPreferences->payload($request->user()),
        ]);
    }

    public function storeCash(Request $request, DepotTransactionBooker $booker): JsonResponse
    {
        $depot = $this->activeDepotOrFail();
        $validated = $request->validate([
            'type' => ['required', Rule::in(DepotTransaction::CashTypes)],
            'stock_holding_id' => ['nullable', Rule::exists(StockHolding::class, 'id')],
            'total_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'booked_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $bookedAt = isset($validated['booked_at'])
            ? $request->date('booked_at')->startOfDay()
            : null;
        $holding = isset($validated['stock_holding_id'])
            ? StockHolding::query()->findOrFail($validated['stock_holding_id'])
            : null;

        $transaction = $booker->bookCash(
            depot: $depot,
            type: $validated['type'],
            holding: $holding,
            totalAmount: (string) $validated['total_amount'],
            note: $validated['note'] ?? null,
            bookedAt: $bookedAt,
            currency: $validated['currency'] ?? 'EUR',
        );

        $depotHoldings = $this->depotHoldingPayloads($transaction->depot->refresh());

        return response()->json([
            'message' => 'Cash transaction booked.',
            'depot' => $this->depotPayload($transaction->depot->refresh()),
            'depot_holdings' => $depotHoldings,
            'depot_valuations' => $this->depotValuationPayloads($transaction->depot, $depotHoldings),
            'depot_performance_series' => $this->depotPerformancePayloads($transaction->depot, $depotHoldings),
            'transaction' => $this->transactionPayload($transaction->load('stockHolding')),
        ], 201);
    }

    public function updateDate(Request $request, DepotTransaction $depotTransaction): JsonResponse
    {
        $depot = $this->activeDepotOrFail();
        $validated = $request->validate([
            'booked_at' => ['required', 'date_format:Y-m-d'],
        ]);

        abort_unless($depotTransaction->depot_id === $depot->id, 404);

        $depotTransaction->update([
            'booked_at' => Carbon::createFromFormat('Y-m-d', $validated['booked_at'])->startOfDay(),
        ]);

        $depotHoldings = $this->depotHoldingPayloads($depot);

        return response()->json([
            'message' => 'Transaction date updated.',
            'depot_holdings' => $depotHoldings,
            'depot_valuations' => $this->depotValuationPayloads($depot, $depotHoldings),
            'depot_performance_series' => $this->depotPerformancePayloads($depot, $depotHoldings),
            'transactions' => $this->transactionPayloads($depot),
            'transaction' => $this->transactionPayload($depotTransaction->refresh()->load('stockHolding')),
        ]);
    }

    public function storeStock(Request $request, DepotTransactionBooker $booker): JsonResponse
    {
        $depot = $this->activeDepotOrFail();
        $validated = $request->validate([
            'type' => ['required', Rule::in(DepotTransaction::StockTypes)],
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
            currency: $holding->currency ?? 'EUR',
        );

        $depotHoldings = $this->depotHoldingPayloads($depot);

        return response()->json([
            'message' => 'Stock transaction booked.',
            'depot' => $this->depotPayload($transaction->depot->refresh()),
            'depot_holdings' => $depotHoldings,
            'depot_valuations' => $this->depotValuationPayloads($transaction->depot, $depotHoldings),
            'depot_performance_series' => $this->depotPerformancePayloads($transaction->depot, $depotHoldings),
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
     * @return array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>
     */
    private function depotHoldingPayloads(Depot $depot): array
    {
        $transactionsByHoldingId = DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereNotNull('stock_holding_id')
            ->whereIn('type', DepotTransaction::StockTypes)
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

        $holdings = StockHolding::query()
            ->with(['latestRealtimePrice', 'latestStockPrice'])
            ->whereKey($positionPiecesByHoldingId->keys()->all())
            ->orderBy('name')
            ->get()
            ->values();
        $previousDailyPriceByHoldingId = $this->previousDailyPriceByHoldingId($holdings);

        return $holdings
            ->map(fn (StockHolding $holding): array => $this->depotHoldingPayload(
                holding: $holding,
                positionPieces: $positionPiecesByHoldingId->get($holding->id, '0.00000000'),
                yearStartPrice: $yearStartPriceByHoldingId->get($holding->id),
                previousDailyPrice: $previousDailyPriceByHoldingId->get($holding->id),
            ))
            ->all();
    }

    /**
     * @param  Collection<int, StockHolding>  $holdings
     * @return Collection<int, StockHoldingDailyPrice>
     */
    private function previousDailyPriceByHoldingId(Collection $holdings): Collection
    {
        if ($holdings->isEmpty()) {
            return collect();
        }

        $latestPriceDateByHoldingId = $holdings
            ->mapWithKeys(fn (StockHolding $holding): array => [$holding->id => $this->latestPriceDate($holding)]);

        return StockHoldingDailyPrice::query()
            ->whereIn('stock_holding_id', $holdings->pluck('id')->all())
            ->where(function ($query): void {
                $query
                    ->whereNotNull('adjusted_close')
                    ->orWhereNotNull('close');
            })
            ->orderByDesc('trading_date')
            ->orderByDesc('id')
            ->get(['id', 'stock_holding_id', 'trading_date', 'close', 'adjusted_close', 'currency'])
            ->groupBy('stock_holding_id')
            ->map(function (Collection $dailyPrices, int $holdingId) use ($latestPriceDateByHoldingId): ?StockHoldingDailyPrice {
                $latestPriceDate = $latestPriceDateByHoldingId->get($holdingId, now()->startOfDay());

                return $dailyPrices->first(
                    fn (StockHoldingDailyPrice $dailyPrice): bool => $dailyPrice->trading_date->lt($latestPriceDate),
                );
            })
            ->filter();
    }

    private function latestPriceDate(StockHolding $holding): Carbon
    {
        $latestStoredPrice = $this->latestStoredPrice($holding);
        $storedPriceDate = $this->storedPriceDate($latestStoredPrice);

        if ($storedPriceDate !== null) {
            return $storedPriceDate;
        }

        if (is_string($holding->latest_price_as_of) && trim($holding->latest_price_as_of) !== '') {
            try {
                return Carbon::parse($holding->latest_price_as_of)->startOfDay();
            } catch (\Throwable) {
                return now()->startOfDay();
            }
        }

        if ($holding->latest_price_fetched_at !== null) {
            return $holding->latest_price_fetched_at->copy()->startOfDay();
        }

        return now()->startOfDay();
    }

    private function storedPriceDate(StockPrice|StockRealtimePrice|null $stockPrice): ?Carbon
    {
        if ($stockPrice === null) {
            return null;
        }

        $asOfDate = $stockPrice->as_of?->copy()->startOfDay();
        $fetchedAtDate = $stockPrice->fetched_at?->copy()->startOfDay();

        if ($stockPrice instanceof StockRealtimePrice && $fetchedAtDate !== null && ($asOfDate === null || $fetchedAtDate->gt($asOfDate))) {
            return $fetchedAtDate;
        }

        return $asOfDate ?? $fetchedAtDate;
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
     * @return array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}
     */
    private function depotHoldingPayload(
        StockHolding $holding,
        string $positionPieces,
        ?string $yearStartPrice,
        ?StockHoldingDailyPrice $previousDailyPrice,
    ): array {
        $latestStoredPrice = $this->latestStoredPrice($holding);
        $latestPrice = $latestStoredPrice?->price ?? $holding->latest_price;
        $previousDayReference = $this->previousDayReference($holding, $latestStoredPrice, $previousDailyPrice);

        return [
            'id' => $holding->id,
            'symbol' => $holding->symbol,
            'name' => $holding->name,
            'isin' => $holding->isin,
            'currency' => $latestStoredPrice?->currency ?? $holding->currency,
            'latest_price' => $latestPrice,
            'previous_day_price' => $previousDayReference['price'],
            'previous_day_price_date' => $previousDayReference['date'],
            'previous_day_change_percent' => $this->priceChangePercent($latestPrice, $previousDayReference['price']),
            'flatex_price' => $holding->flatex_price,
            'year_start_price' => $yearStartPrice,
            'latest_price_fetched_at' => $latestStoredPrice?->fetched_at?->toIso8601String() ?? $holding->latest_price_fetched_at?->toIso8601String(),
            'latest_price_status' => $this->latestPriceStatus($holding, $latestStoredPrice, $latestPrice),
            'position_pieces' => $positionPieces,
        ];
    }

    /**
     * @return array{price: ?string, date: ?string}
     */
    private function previousDayReference(
        StockHolding $holding,
        StockPrice|StockRealtimePrice|null $latestStoredPrice,
        ?StockHoldingDailyPrice $previousDailyPrice,
    ): array {
        $dailyPrice = $previousDailyPrice?->close ?? $previousDailyPrice?->adjusted_close;
        $dailyDate = $previousDailyPrice?->trading_date?->copy()->startOfDay();
        $previousStoredPrice = $this->previousStoredPriceForLatestRealtime($holding, $latestStoredPrice);
        $previousStoredPriceDate = $previousStoredPrice instanceof StockRealtimePrice
            ? $previousStoredPrice->as_of?->copy()->startOfDay()
            : $this->storedPriceDate($previousStoredPrice);

        if ($previousStoredPrice?->price !== null && $previousStoredPriceDate !== null && ($dailyDate === null || $previousStoredPriceDate->gt($dailyDate))) {
            return [
                'price' => $previousStoredPrice->price,
                'date' => $previousStoredPriceDate->toDateString(),
            ];
        }

        return [
            'price' => $dailyPrice,
            'date' => $previousDailyPrice?->trading_date?->toDateString(),
        ];
    }

    private function previousStoredPriceForLatestRealtime(StockHolding $holding, StockPrice|StockRealtimePrice|null $latestStoredPrice): StockPrice|StockRealtimePrice|null
    {
        if (! $latestStoredPrice instanceof StockRealtimePrice) {
            return null;
        }

        $previousRealtimePrice = $this->previousRealtimePriceForLatestRealtime($holding, $latestStoredPrice);

        if ($previousRealtimePrice !== null) {
            return $previousRealtimePrice;
        }

        $latestStoredPriceDate = $this->storedPriceDate($latestStoredPrice);
        $previousStoredPrice = $holding->latestStockPrice;
        $previousStoredPriceDate = $this->storedPriceDate($previousStoredPrice);

        if ($previousStoredPrice?->price === null || $latestStoredPriceDate === null || $previousStoredPriceDate === null) {
            return null;
        }

        return $previousStoredPriceDate->lt($latestStoredPriceDate)
            ? $previousStoredPrice
            : null;
    }

    private function previousRealtimePriceForLatestRealtime(StockHolding $holding, StockRealtimePrice $latestStoredPrice): ?StockRealtimePrice
    {
        $latestPriceDate = $latestStoredPrice->as_of?->copy()->startOfDay();

        if ($latestPriceDate === null) {
            return null;
        }

        return StockRealtimePrice::query()
            ->where('stock_holding_id', $holding->id)
            ->whereNotNull('price')
            ->whereDate('as_of', '<', $latestPriceDate->toDateString())
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->first();
    }

    private function latestStoredPrice(StockHolding $holding): StockPrice|StockRealtimePrice|null
    {
        if ($holding->latestRealtimePrice?->price !== null) {
            return $holding->latestRealtimePrice;
        }

        if ($holding->latestStockPrice?->price !== null) {
            return $holding->latestStockPrice;
        }

        return $holding->latestRealtimePrice ?? $holding->latestStockPrice;
    }

    private function priceChangePercent(?string $currentPrice, ?string $referencePrice): ?string
    {
        if (! is_numeric($currentPrice) || ! is_numeric($referencePrice) || (float) $referencePrice === 0.0) {
            return null;
        }

        return $this->decimal((((float) $currentPrice - (float) $referencePrice) / (float) $referencePrice) * 100, 2);
    }

    private function latestPriceStatus(StockHolding $holding, StockPrice|StockRealtimePrice|null $latestStoredPrice, ?string $latestPrice): string
    {
        $storedPriceStatus = $this->storedPriceStatus($latestStoredPrice);

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

    /**
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $depotHoldings
     * @return array{latest: array<string, string>, flatex: array<string, string>}
     */
    private function depotValuationPayloads(Depot $depot, array $depotHoldings): array
    {
        return [
            'latest' => $this->depotValuationPayload($depot, $depotHoldings, 'latest'),
            'flatex' => $this->depotValuationPayload($depot, $depotHoldings, 'flatex'),
        ];
    }

    /**
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $depotHoldings
     * @return array<string, string>
     */
    private function depotValuationPayload(Depot $depot, array $depotHoldings, string $source): array
    {
        $stockBalance = collect($depotHoldings)->sum(function (array $holding) use ($source): float {
            $price = $source === 'flatex'
                ? $holding['flatex_price']
                : $holding['latest_price'];
            $pieces = $holding['position_pieces'];

            if (! is_numeric($price) || ! is_numeric($pieces)) {
                return 0.0;
            }

            return (float) $price * (float) $pieces;
        });
        $cashBalance = (float) $depot->account_balance;
        $currentBalance = $cashBalance + $stockBalance;
        $cashFlowSummary = $this->cashFlowSummary($depot, $cashBalance, now()->endOfDay());
        $balanceChangeWithBrokerBonusAmount = $currentBalance
            + $cashFlowSummary['total_withdrawals']
            - $cashFlowSummary['total_deposits']
            - $cashFlowSummary['opening_balance'];
        $balanceChangeAmount = $balanceChangeWithBrokerBonusAmount - $cashFlowSummary['broker_bonus_amount'];
        $yearStartBalance = $currentBalance - $balanceChangeWithBrokerBonusAmount;
        $oneWeekStartCutoff = now()->subWeek()->endOfDay();
        $oneWeekStartBalance = $this->cashBalanceAt($depot, $oneWeekStartCutoff)
            + $this->stockMarketBalanceAt($depot, $oneWeekStartCutoff)
            + $this->externalCashFlowAfter($depot, $oneWeekStartCutoff);
        $monthStartCutoff = now()->startOfMonth()->endOfDay();
        $monthStartBalance = $this->cashBalanceAt($depot, $monthStartCutoff)
            + $this->stockMarketBalanceAt($depot, $monthStartCutoff)
            + $this->externalCashFlowAfter($depot, $monthStartCutoff);
        $balanceChangePercent = $yearStartBalance === 0.0
            ? 0.0
            : ($balanceChangeAmount / $yearStartBalance) * 100;
        $monthChangeAmount = $currentBalance - $monthStartBalance;
        $monthChangePercent = $monthStartBalance === 0.0
            ? 0.0
            : ($monthChangeAmount / $monthStartBalance) * 100;
        $oneWeekChangeAmount = $currentBalance - $oneWeekStartBalance;
        $oneWeekChangePercent = $oneWeekStartBalance === 0.0
            ? 0.0
            : ($oneWeekChangeAmount / $oneWeekStartBalance) * 100;
        $taxableStockGainAmount = max($stockBalance - $this->stockBalanceAt($depot, now()->endOfDay()), 0.0);

        return [
            'stock_balance' => $this->decimal($stockBalance, 2),
            'cash_balance' => $this->decimal($cashBalance, 2),
            'account_balance' => $this->decimal($cashBalance + $stockBalance, 2),
            'year_start_balance' => $this->decimal($yearStartBalance, 2),
            'current_balance' => $this->decimal($currentBalance, 2),
            'balance_change_amount' => $this->decimal($balanceChangeAmount, 2),
            'balance_change_percent' => $this->decimal($balanceChangePercent, 2),
            'balance_change_with_broker_bonus_amount' => $this->decimal($balanceChangeWithBrokerBonusAmount, 2),
            'balance_change_with_broker_bonus_percent' => $this->decimal($yearStartBalance === 0.0 ? 0.0 : ($balanceChangeWithBrokerBonusAmount / $yearStartBalance) * 100, 2),
            'taxable_stock_gain_amount' => $this->decimal($taxableStockGainAmount, 2),
            'opening_balance' => $this->decimal($cashFlowSummary['opening_balance'], 2),
            'total_deposits' => $this->decimal($cashFlowSummary['total_deposits'], 2),
            'total_withdrawals' => $this->decimal($cashFlowSummary['total_withdrawals'], 2),
            'dividend_amount' => $this->decimal($cashFlowSummary['dividend_amount'], 2),
            'interest_amount' => $this->decimal($cashFlowSummary['interest_amount'], 2),
            'fee_amount' => $this->decimal($cashFlowSummary['fee_amount'], 2),
            'tax_amount' => $this->decimal($cashFlowSummary['tax_amount'], 2),
            'broker_bonus_amount' => $this->decimal($cashFlowSummary['broker_bonus_amount'], 2),
            'month_start_balance' => $this->decimal($monthStartBalance, 2),
            'month_change_amount' => $this->decimal($monthChangeAmount, 2),
            'month_change_percent' => $this->decimal($monthChangePercent, 2),
            'one_week_start_balance' => $this->decimal($oneWeekStartBalance, 2),
            'one_week_change_amount' => $this->decimal($oneWeekChangeAmount, 2),
            'one_week_change_percent' => $this->decimal($oneWeekChangePercent, 2),
        ];
    }

    private function cashBalanceAt(Depot $depot, Carbon $cutoff): float
    {
        return $this->initialCashBalance($depot) + (float) DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->where('booked_at', '<=', $cutoff)
            ->sum('cash_delta');
    }

    private function initialCashBalance(Depot $depot): float
    {
        $cashDelta = (float) DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->where('booked_at', '<=', now()->endOfDay())
            ->sum('cash_delta');

        return round((float) $depot->account_balance - $cashDelta, 2);
    }

    /**
     * @return array{
     *     opening_balance: float,
     *     total_deposits: float,
     *     total_withdrawals: float,
     *     dividend_amount: float,
     *     interest_amount: float,
     *     fee_amount: float,
     *     tax_amount: float,
     *     broker_bonus_amount: float
     * }
     */
    private function cashFlowSummary(Depot $depot, float $currentCashBalance, Carbon $cutoff): array
    {
        $transactions = DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereIn('type', DepotTransaction::CashTypes)
            ->where('booked_at', '<=', $cutoff)
            ->get(['type', 'total_amount', 'cash_delta']);
        $openingBalance = $this->cashAmountByType($transactions, 'opening_balance', 'cash_delta');
        $cashDelta = (float) DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->where('booked_at', '<=', $cutoff)
            ->sum('cash_delta');
        $inferredOpeningBalance = $openingBalance > 0.0
            ? 0.0
            : max(round($currentCashBalance - $cashDelta, 2), 0.0);

        return [
            'opening_balance' => $openingBalance + $inferredOpeningBalance,
            'total_deposits' => $this->cashAmountByType($transactions, 'deposit'),
            'total_withdrawals' => $this->cashAmountByType($transactions, 'withdrawal'),
            'dividend_amount' => $this->cashAmountByType($transactions, 'dividend'),
            'interest_amount' => $this->cashAmountByType($transactions, 'interest'),
            'fee_amount' => $this->cashAmountByType($transactions, 'fee'),
            'tax_amount' => $this->cashAmountByType($transactions, 'tax'),
            'broker_bonus_amount' => $this->cashAmountByType($transactions, 'broker_bonus'),
        ];
    }

    /**
     * @param  Collection<int, DepotTransaction>  $transactions
     */
    private function cashAmountByType(Collection $transactions, string $type, string $amountColumn = 'total_amount'): float
    {
        return (float) $transactions
            ->filter(fn (DepotTransaction $transaction): bool => $transaction->type === $type)
            ->sum(fn (DepotTransaction $transaction): float => abs((float) $transaction->{$amountColumn}));
    }

    private function externalCashFlowAfter(Depot $depot, Carbon $cutoff): float
    {
        return (float) DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereIn('type', DepotTransaction::ExternalCashflowTypes)
            ->where('booked_at', '>', $cutoff)
            ->where('booked_at', '<=', now()->endOfDay())
            ->sum('cash_delta');
    }

    private function stockBalanceAt(Depot $depot, Carbon $cutoff): float
    {
        $transactionsByHoldingId = DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereNotNull('stock_holding_id')
            ->whereIn('type', DepotTransaction::StockTypes)
            ->where('booked_at', '<=', $cutoff)
            ->orderBy('booked_at')
            ->orderBy('id')
            ->get(['stock_holding_id', 'type', 'pieces', 'total_amount', 'booked_at'])
            ->groupBy('stock_holding_id');

        return $transactionsByHoldingId->sum(function (Collection $transactions): float {
            return collect($this->openBuyLots($transactions))
                ->sum(fn (array $lot): float => $lot['total_amount']);
        });
    }

    private function stockMarketBalanceAt(Depot $depot, Carbon $cutoff): float
    {
        $openPiecesByHoldingId = DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereNotNull('stock_holding_id')
            ->whereIn('type', DepotTransaction::StockTypes)
            ->where('booked_at', '<=', $cutoff)
            ->orderBy('booked_at')
            ->orderBy('id')
            ->get(['stock_holding_id', 'type', 'pieces', 'total_amount', 'booked_at'])
            ->groupBy('stock_holding_id')
            ->map(fn (Collection $transactions): float => collect($this->openBuyLots($transactions))
                ->sum(fn (array $lot): float => $lot['pieces']))
            ->filter(fn (float $pieces): bool => $pieces > 0);

        if ($openPiecesByHoldingId->isEmpty()) {
            return 0.0;
        }

        $dailyPriceByHoldingId = StockHoldingDailyPrice::query()
            ->whereIn('stock_holding_id', $openPiecesByHoldingId->keys()->all())
            ->where('trading_date', '<=', $cutoff->toDateString())
            ->where(function ($query): void {
                $query
                    ->whereNotNull('adjusted_close')
                    ->orWhereNotNull('close');
            })
            ->orderByDesc('trading_date')
            ->orderByDesc('id')
            ->get(['stock_holding_id', 'adjusted_close', 'close'])
            ->groupBy('stock_holding_id')
            ->map(fn (Collection $dailyPrices): ?StockHoldingDailyPrice => $dailyPrices->first());

        return $openPiecesByHoldingId->sum(function (float $pieces, int $holdingId) use ($dailyPriceByHoldingId): float {
            $dailyPrice = $dailyPriceByHoldingId->get($holdingId);
            $price = $dailyPrice?->adjusted_close ?? $dailyPrice?->close;

            return is_numeric($price) ? $pieces * (float) $price : 0.0;
        });
    }

    /**
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $currentDepotHoldings
     * @return array<int, array{date: string, stock_balance: string, cash_balance: string, account_balance: string}>
     */
    private function depotPerformancePayloads(Depot $depot, array $currentDepotHoldings): array
    {
        $start = now()->startOfYear();
        $today = now()->startOfDay();
        $transactions = DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->where('booked_at', '<=', $today->copy()->endOfDay())
            ->orderBy('booked_at')
            ->orderBy('id')
            ->get(['id', 'stock_holding_id', 'type', 'pieces', 'total_amount', 'cash_delta', 'balance_after', 'booked_at']);
        $initialCashBalance = round((float) $depot->account_balance - $transactions->sum(
            fn (DepotTransaction $transaction): float => (float) $transaction->cash_delta,
        ), 2);
        $stockTransactionsByHoldingId = $transactions
            ->whereNotNull('stock_holding_id')
            ->whereIn('type', DepotTransaction::StockTypes)
            ->groupBy('stock_holding_id');
        $dailyPricesByHoldingId = $this->dailyPricesByHoldingId($stockTransactionsByHoldingId->keys());
        $points = [];

        for ($date = $start->copy(); $date->lte($today); $date->addDay()) {
            $cutoff = $date->copy()->endOfDay();
            $isToday = $date->isSameDay($today);
            $cashBalance = $isToday
                ? (float) $depot->account_balance
                : $this->cashBalanceFromTransactions($transactions, $cutoff, $initialCashBalance);
            $cashBalance += $this->externalCashFlowFromTransactionsAfter($transactions, $cutoff);
            $stockBalance = $isToday
                ? $this->currentStockBalance($currentDepotHoldings)
                : $this->stockMarketBalanceFromCollections($stockTransactionsByHoldingId, $dailyPricesByHoldingId, $cutoff);

            $points[] = [
                'date' => $date->toDateString(),
                'stock_balance' => $this->decimal($stockBalance, 2),
                'cash_balance' => $this->decimal($cashBalance, 2),
                'account_balance' => $this->decimal($cashBalance + $stockBalance, 2),
            ];
        }

        return $points;
    }

    /**
     * @param  Collection<int, int|string>  $holdingIds
     * @return Collection<int, Collection<int, StockHoldingDailyPrice>>
     */
    private function dailyPricesByHoldingId(Collection $holdingIds): Collection
    {
        if ($holdingIds->isEmpty()) {
            return collect();
        }

        return StockHoldingDailyPrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->where('trading_date', '<=', now()->toDateString())
            ->where(function ($query): void {
                $query
                    ->whereNotNull('adjusted_close')
                    ->orWhereNotNull('close');
            })
            ->orderByDesc('trading_date')
            ->orderByDesc('id')
            ->get(['id', 'stock_holding_id', 'trading_date', 'close', 'adjusted_close'])
            ->groupBy('stock_holding_id');
    }

    /**
     * @param  Collection<int, DepotTransaction>  $transactions
     */
    private function cashBalanceFromTransactions(Collection $transactions, Carbon $cutoff, float $initialCashBalance = 0.0): float
    {
        return $initialCashBalance + (float) $transactions
            ->filter(fn (DepotTransaction $transaction): bool => $transaction->booked_at?->lte($cutoff) ?? false)
            ->sum(fn (DepotTransaction $transaction): float => (float) $transaction->cash_delta);
    }

    /**
     * @param  Collection<int, DepotTransaction>  $transactions
     */
    private function externalCashFlowFromTransactionsAfter(Collection $transactions, Carbon $cutoff): float
    {
        return (float) $transactions
            ->filter(fn (DepotTransaction $transaction): bool => (
                in_array($transaction->type, DepotTransaction::ExternalCashflowTypes, true)
                && ($transaction->booked_at?->gt($cutoff) ?? false)
                && ($transaction->booked_at?->lte(now()->endOfDay()) ?? false)
            ))
            ->sum(fn (DepotTransaction $transaction): float => (float) $transaction->cash_delta);
    }

    /**
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $currentDepotHoldings
     */
    private function currentStockBalance(array $currentDepotHoldings): float
    {
        return collect($currentDepotHoldings)->sum(function (array $holding): float {
            if (! is_numeric($holding['latest_price']) || ! is_numeric($holding['position_pieces'])) {
                return 0.0;
            }

            return (float) $holding['latest_price'] * (float) $holding['position_pieces'];
        });
    }

    /**
     * @param  Collection<int, Collection<int, DepotTransaction>>  $stockTransactionsByHoldingId
     * @param  Collection<int, Collection<int, StockHoldingDailyPrice>>  $dailyPricesByHoldingId
     */
    private function stockMarketBalanceFromCollections(
        Collection $stockTransactionsByHoldingId,
        Collection $dailyPricesByHoldingId,
        Carbon $cutoff,
    ): float {
        return $stockTransactionsByHoldingId->sum(function (Collection $transactions, int $holdingId) use ($dailyPricesByHoldingId, $cutoff): float {
            $openPieces = collect($this->openBuyLots(
                $transactions->filter(fn (DepotTransaction $transaction): bool => $transaction->booked_at?->lte($cutoff) ?? false),
            ))->sum(fn (array $lot): float => $lot['pieces']);

            if ($openPieces <= 0) {
                return 0.0;
            }

            $dailyPrice = $dailyPricesByHoldingId
                ->get($holdingId, collect())
                ->first(fn (StockHoldingDailyPrice $price): bool => $price->trading_date->lte($cutoff));
            $price = $dailyPrice?->adjusted_close ?? $dailyPrice?->close;

            return is_numeric($price) ? $openPieces * (float) $price : 0.0;
        });
    }

    private function decimal(float $value, int $places): string
    {
        return number_format($value, $places, '.', '');
    }

    /**
     * @return array<int, array{id: int, type: string, stock_holding_id: ?int, stock_label: ?string, stock_isin: ?string, pieces: ?string, total_amount: string, currency: string, unit_price: ?string, cash_delta: string, balance_after: string, booked_at: ?string, note: ?string, is_external_cashflow: bool, affects_performance: bool}>
     */
    private function transactionPayloads(Depot $depot): array
    {
        return DepotTransaction::query()
            ->with('stockHolding')
            ->where('depot_id', $depot->id)
            ->latest('booked_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (DepotTransaction $transaction): array => $this->transactionPayload($transaction))
            ->all();
    }

    /**
     * @return array{id: int, type: string, stock_holding_id: ?int, stock_label: ?string, stock_isin: ?string, pieces: ?string, total_amount: string, currency: string, unit_price: ?string, cash_delta: string, balance_after: string, booked_at: ?string, note: ?string, is_external_cashflow: bool, affects_performance: bool}
     */
    private function transactionPayload(DepotTransaction $transaction): array
    {
        $holding = $transaction->stockHolding;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'stock_holding_id' => $transaction->stock_holding_id,
            'stock_label' => $holding?->name ?? $holding?->symbol,
            'stock_isin' => $holding?->isin,
            'pieces' => $transaction->pieces,
            'total_amount' => $transaction->total_amount,
            'currency' => $transaction->currency ?? 'EUR',
            'unit_price' => $transaction->unit_price,
            'cash_delta' => $transaction->cash_delta,
            'balance_after' => $transaction->balance_after,
            'booked_at' => $transaction->booked_at?->toIso8601String(),
            'note' => $transaction->note,
            'is_external_cashflow' => $transaction->is_external_cashflow,
            'affects_performance' => $transaction->affects_performance,
        ];
    }
}
