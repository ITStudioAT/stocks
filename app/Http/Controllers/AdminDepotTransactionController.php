<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Services\DepotTransactionBooker;
use App\Services\StockPreviousCloseResolver;
use App\Services\StockPriceCatalog;
use App\Services\UiPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminDepotTransactionController extends Controller
{
    public function __construct(
        private StockPreviousCloseResolver $stockPreviousCloseResolver,
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

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

    public function dailyPerformance(): JsonResponse
    {
        $depot = $this->activeDepot();

        if (! $depot) {
            return response()->json([
                'days' => [],
                'sums' => [],
            ]);
        }

        $depotHoldings = $this->depotHoldingPayloads($depot);
        $depotValuation = $this->depotValuationPayload(
            $depot,
            $depotHoldings,
            'latest',
            $this->previousDayAccountBalance($depot),
        );

        return response()->json([
            'days' => $this->dailyPerformancePayloads($depot, $depotHoldings),
            'sums' => $this->dashboardPerformanceSumPayloads($depot, $depotValuation),
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

        return $holdings
            ->map(fn (StockHolding $holding): array => $this->depotHoldingPayload(
                holding: $holding,
                positionPieces: $positionPiecesByHoldingId->get($holding->id, '0.00000000'),
                yearStartPrice: $yearStartPriceByHoldingId->get($holding->id),
            ))
            ->all();
    }

    private function latestPriceDate(StockHolding $holding): Carbon
    {
        $latestStoredPrice = $this->latestStoredPrice($holding);
        $storedPriceDate = $latestStoredPrice?->as_of?->copy()->setTimezone('Europe/Vienna')->startOfDay();

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
     * @return array{id: int, symbol: ?string, name: ?string, subtitle: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}
     */
    private function depotHoldingPayload(
        StockHolding $holding,
        string $positionPieces,
        ?string $yearStartPrice,
    ): array {
        $latestStoredPrice = $this->latestStoredPrice($holding);
        $latestPrice = $latestStoredPrice?->price ?? $holding->latest_price;
        $previousCloses = $this->stockPreviousCloseResolver->resolve($holding, $this->latestPriceDate($holding));

        return [
            'id' => $holding->id,
            'symbol' => $holding->symbol,
            'name' => $holding->name,
            'subtitle' => $holding->subtitle,
            'isin' => $holding->isin,
            'currency' => $latestStoredPrice?->currency ?? $holding->currency,
            'latest_price' => $latestPrice,
            'previous_day_price' => $previousCloses['previous_close'],
            'previous_day_price_date' => $previousCloses['previous_close_date'],
            'previous_day_change_percent' => $this->priceChangePercent($latestPrice, $previousCloses['previous_close']),
            'flatex_price' => $holding->flatex_price,
            'year_start_price' => $yearStartPrice,
            'latest_price_fetched_at' => $latestStoredPrice?->fetched_at?->toIso8601String() ?? $holding->latest_price_fetched_at?->toIso8601String(),
            'latest_price_status' => $this->latestPriceStatus($holding, $latestStoredPrice, $latestPrice),
            'position_pieces' => $positionPieces,
        ];
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
        $previousDayBalance = $this->previousDayAccountBalance($depot);

        return [
            'latest' => $this->depotValuationPayload($depot, $depotHoldings, 'latest', $previousDayBalance),
            'flatex' => $this->depotValuationPayload($depot, $depotHoldings, 'flatex', $previousDayBalance),
        ];
    }

    /**
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $depotHoldings
     * @return array<string, string>
     */
    private function depotValuationPayload(Depot $depot, array $depotHoldings, string $source, float $previousDayBalance): array
    {
        $stockBalance = $this->holdingStockBalance(
            $depotHoldings,
            $source === 'flatex' ? 'flatex_price' : 'latest_price',
        );
        $cashBalance = (float) $depot->account_balance;
        $currentBalance = $cashBalance + $stockBalance;
        $previousDayChangeAmount = $currentBalance - $previousDayBalance;
        $previousDayChangePercent = $previousDayBalance === 0.0
            ? 0.0
            : ($previousDayChangeAmount / $previousDayBalance) * 100;
        $cashFlowSummary = $this->cashFlowSummary($depot, $cashBalance, now()->endOfDay());
        $balanceChangeWithBrokerBonusAmount = $currentBalance
            + $cashFlowSummary['total_withdrawals']
            - $cashFlowSummary['total_deposits']
            - $cashFlowSummary['opening_balance'];
        $balanceChangeAmount = $balanceChangeWithBrokerBonusAmount - $cashFlowSummary['broker_bonus_amount'];
        $yearStartBalance = $currentBalance - $balanceChangeWithBrokerBonusAmount;
        $oneWeekStartCutoff = now()->startOfWeek()->subSecond();
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
        $taxableStockGainAmount = $this->taxableStockGainAmount($depot, $depotHoldings, $source);

        return [
            'stock_balance' => $this->decimal($stockBalance, 2),
            'cash_balance' => $this->decimal($cashBalance, 2),
            'account_balance' => $this->decimal($cashBalance + $stockBalance, 2),
            'previous_day_balance' => $this->decimal($previousDayBalance, 2),
            'previous_day_change_amount' => $this->decimal($previousDayChangeAmount, 2),
            'previous_day_change_percent' => $this->decimal($previousDayChangePercent, 2),
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

    private function previousDayAccountBalance(Depot $depot): float
    {
        $previousDayCutoff = now()->subDay()->endOfDay();
        $openPiecesByHoldingId = $this->openPiecesByHoldingIdAt($depot, $previousDayCutoff);
        $stockBalance = StockHolding::query()
            ->whereKey($openPiecesByHoldingId->keys()->all())
            ->get()
            ->sum(function (StockHolding $holding) use ($openPiecesByHoldingId, $previousDayCutoff): float {
                $previousClose = $this->stockPreviousCloseResolver->resolve(
                    $holding,
                    $previousDayCutoff->copy()->addDay()->startOfDay(),
                )['previous_close'];

                if (! is_numeric($previousClose)) {
                    return 0.0;
                }

                return (float) $previousClose * $openPiecesByHoldingId->get($holding->id, 0.0);
            });

        return $this->cashBalanceAt($depot, $previousDayCutoff) + $stockBalance;
    }

    /**
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $depotHoldings
     * @return array<int, array{date: string, is_live: bool, account_balance: ?string, previous_balance: ?string, change_amount: ?string, change_percent: ?string}>
     */
    private function dailyPerformancePayloads(Depot $depot, array $depotHoldings): array
    {
        $holdingIds = collect($depotHoldings)->pluck('id');

        if ($holdingIds->isEmpty()) {
            return [];
        }

        $performanceDates = $this->currentWeekPerformanceDates();
        $latestPerformanceDate = $this->latestDailyPerformanceDate($holdingIds);

        $isLivePerformanceDate = $latestPerformanceDate !== null
            && $this->isLivePerformanceDate($holdingIds, $latestPerformanceDate);
        $currentBalance = (float) $depot->account_balance + $this->holdingStockBalance($depotHoldings, 'latest_price');
        $balanceDates = collect([$performanceDates->first()])
            ->map(fn (string $date): string => Carbon::parse($date)->subDay()->toDateString())
            ->merge($performanceDates)
            ->filter(fn (string $date): bool => $latestPerformanceDate !== null && $date <= $latestPerformanceDate);
        $balances = $balanceDates->mapWithKeys(function (string $date) use ($currentBalance, $depot, $isLivePerformanceDate, $latestPerformanceDate): array {
            $balance = $isLivePerformanceDate && $date === $latestPerformanceDate
                ? $currentBalance
                : $this->performanceAccountBalanceAt($depot, Carbon::parse($date)->endOfDay());

            return [$date => $balance];
        });

        return $performanceDates
            ->map(function (string $date) use ($balances, $isLivePerformanceDate, $latestPerformanceDate): array {
                if ($latestPerformanceDate === null || $date > $latestPerformanceDate) {
                    return [
                        'date' => $date,
                        'is_live' => false,
                        'account_balance' => null,
                        'previous_balance' => null,
                        'change_amount' => null,
                        'change_percent' => null,
                    ];
                }

                $previousDate = Carbon::parse($date)->subDay()->toDateString();
                $accountBalance = (float) $balances->get($date, 0.0);
                $previousBalance = (float) $balances->get($previousDate, 0.0);
                $changeAmount = $accountBalance - $previousBalance;
                $changePercent = $previousBalance === 0.0
                    ? null
                    : ($changeAmount / $previousBalance) * 100;

                return [
                    'date' => $date,
                    'is_live' => $isLivePerformanceDate && $date === $latestPerformanceDate,
                    'account_balance' => $this->decimal($accountBalance, 2),
                    'previous_balance' => $this->decimal($previousBalance, 2),
                    'change_amount' => $this->decimal($changeAmount, 2),
                    'change_percent' => $changePercent === null ? null : $this->decimal($changePercent, 2),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, string>  $depotValuation
     * @return array<int, array{period: string, change_amount: string, change_percent: string}>
     */
    private function dashboardPerformanceSumPayloads(Depot $depot, array $depotValuation): array
    {
        $lastWeekStart = now()->startOfWeek(Carbon::MONDAY)->subWeek()->startOfDay();
        $lastMonthStart = now()->startOfMonth()->subMonth()->startOfDay();

        return [
            [
                'period' => 'week',
                'change_amount' => $depotValuation['one_week_change_amount'],
                'change_percent' => $depotValuation['one_week_change_percent'],
            ],
            $this->historicalPerformanceSumPayload(
                $depot,
                'last_week',
                $lastWeekStart,
                $lastWeekStart->copy()->addDays(6)->endOfDay(),
            ),
            [
                'period' => 'month',
                'change_amount' => $depotValuation['month_change_amount'],
                'change_percent' => $depotValuation['month_change_percent'],
            ],
            $this->historicalPerformanceSumPayload(
                $depot,
                'last_month',
                $lastMonthStart,
                $lastMonthStart->copy()->endOfMonth(),
            ),
            [
                'period' => 'year',
                'change_amount' => $depotValuation['balance_change_amount'],
                'change_percent' => $depotValuation['balance_change_percent'],
            ],
        ];
    }

    /**
     * @return array{period: string, change_amount: string, change_percent: string}
     */
    private function historicalPerformanceSumPayload(
        Depot $depot,
        string $period,
        Carbon $start,
        Carbon $end,
    ): array {
        $previousBalance = $this->performanceAccountBalanceAt($depot, $start->copy()->subSecond(), $end);
        $accountBalance = $this->performanceAccountBalanceAt($depot, $end, $end);
        $changeAmount = $accountBalance - $previousBalance;
        $changePercent = $previousBalance === 0.0
            ? 0.0
            : ($changeAmount / $previousBalance) * 100;

        return [
            'period' => $period,
            'change_amount' => $this->decimal($changeAmount, 2),
            'change_percent' => $this->decimal($changePercent, 2),
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function currentWeekPerformanceDates(): Collection
    {
        $weekStart = now()->startOfWeek(Carbon::MONDAY)->startOfDay();

        return collect(range(0, 4))
            ->map(fn (int $dayOffset): string => $weekStart->copy()->addDays($dayOffset)->toDateString());
    }

    /**
     * @param  Collection<int, int|string>  $holdingIds
     */
    private function latestDailyPerformanceDate(Collection $holdingIds): ?string
    {
        $instrumentKeys = StockHolding::query()
            ->whereKey($holdingIds->all())
            ->get(['id', 'isin', 'wkn', 'symbol', 'mic_code', 'exchange'])
            ->map(fn (StockHolding $holding): string => $this->stockPriceCatalog->instrumentKeyForHolding($holding))
            ->unique()
            ->values();
        $dailyPriceDates = StockHoldingDailyPrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->where('trading_date', '<=', now()->toDateString())
            ->where(function ($query): void {
                $query
                    ->whereNotNull('adjusted_close')
                    ->orWhereNotNull('close');
            })
            ->distinct()
            ->orderByDesc('trading_date')
            ->limit(1)
            ->pluck('trading_date')
            ->map(fn (Carbon|string $date): string => $date instanceof Carbon ? $date->toDateString() : $date);

        $realtimePriceDates = StockRealtimePrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '<=', now()->endOfDay())
            ->selectRaw('DATE(as_of) as performance_date')
            ->distinct()
            ->orderByDesc('performance_date')
            ->limit(1)
            ->pluck('performance_date');
        $officialPriceDates = StockPrice::query()
            ->whereIn('instrument_key', $instrumentKeys->all())
            ->where('source_key', 'eodhd_eod')
            ->where('price_type', 'historical_eod')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '<=', now()->endOfDay())
            ->selectRaw('DATE(as_of) as performance_date')
            ->distinct()
            ->orderByDesc('performance_date')
            ->limit(1)
            ->pluck('performance_date');

        $latestPerformanceDate = $dailyPriceDates
            ->merge($realtimePriceDates)
            ->merge($officialPriceDates)
            ->filter()
            ->map(fn ($date): string => (string) $date)
            ->unique()
            ->sortDesc()
            ->first();

        if (! $latestPerformanceDate) {
            return null;
        }

        return $latestPerformanceDate;
    }

    /**
     * @param  Collection<int, int|string>  $holdingIds
     */
    private function isLivePerformanceDate(Collection $holdingIds, string $date): bool
    {
        if ($date !== now()->toDateString()) {
            return false;
        }

        return StockRealtimePrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->whereDate('as_of', $date)
            ->whereNotNull('price')
            ->whereIn('freshness_status', ['realtime', 'fresh', 'delayed'])
            ->exists();
    }

    private function performanceAccountBalanceAt(Depot $depot, Carbon $cutoff, ?Carbon $cashFlowEnd = null): float
    {
        return $this->cashBalanceAt($depot, $cutoff)
            + $this->stockMarketBalanceAt($depot, $cutoff)
            + $this->externalCashFlowAfter($depot, $cutoff, $cashFlowEnd);
    }

    /**
     * @param  array<int, array<string, mixed>>  $depotHoldings
     */
    private function holdingStockBalance(array $depotHoldings, string $priceKey): float
    {
        return collect($depotHoldings)->sum(function (array $holding) use ($priceKey): float {
            $price = $holding[$priceKey] ?? null;
            $pieces = $holding['position_pieces'] ?? null;

            if (! is_numeric($price) || ! is_numeric($pieces)) {
                return 0.0;
            }

            return (float) $price * (float) $pieces;
        });
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

    private function externalCashFlowAfter(Depot $depot, Carbon $cutoff, ?Carbon $cashFlowEnd = null): float
    {
        return (float) DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereIn('type', DepotTransaction::ExternalCashflowTypes)
            ->where('booked_at', '>', $cutoff)
            ->where('booked_at', '<=', $cashFlowEnd ?? now()->endOfDay())
            ->sum('cash_delta');
    }

    private function stockBalanceAt(Depot $depot, Carbon $cutoff): float
    {
        return $this->openBuyLotCostByHoldingId($depot, $cutoff)
            ->sum();
    }

    /**
     * @return Collection<int, float>
     */
    private function openBuyLotCostByHoldingId(Depot $depot, Carbon $cutoff): Collection
    {
        return DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereNotNull('stock_holding_id')
            ->whereIn('type', DepotTransaction::StockTypes)
            ->where('booked_at', '<=', $cutoff)
            ->orderBy('booked_at')
            ->orderBy('id')
            ->get(['stock_holding_id', 'type', 'pieces', 'total_amount', 'booked_at'])
            ->groupBy('stock_holding_id')
            ->map(fn (Collection $transactions): float => collect($this->openBuyLots($transactions))
                ->sum(fn (array $lot): float => $lot['total_amount']));
    }

    /**
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $depotHoldings
     */
    private function taxableStockGainAmount(Depot $depot, array $depotHoldings, string $source): float
    {
        $openBuyLotCostByHoldingId = $this->openBuyLotCostByHoldingId($depot, now()->endOfDay());
        $openBuyLotCost = $openBuyLotCostByHoldingId->sum();

        $holdingBalance = collect($depotHoldings)->sum(function (array $holding) use ($source): float {
            $price = $source === 'flatex'
                ? $holding['flatex_price']
                : $holding['latest_price'];
            $pieces = $holding['position_pieces'];

            if (! is_numeric($price) || ! is_numeric($pieces)) {
                return 0.0;
            }

            return (float) $price * (float) $pieces;
        });

        return max($holdingBalance - $openBuyLotCost, 0.0);
    }

    private function stockMarketBalanceAt(Depot $depot, Carbon $cutoff): float
    {
        $openPiecesByHoldingId = $this->openPiecesByHoldingIdAt($depot, $cutoff);

        if ($openPiecesByHoldingId->isEmpty()) {
            return 0.0;
        }

        $marketPriceByHoldingId = $this->historicalMarketPriceByHoldingId($openPiecesByHoldingId->keys(), $cutoff);

        return $openPiecesByHoldingId->sum(function (float $pieces, int $holdingId) use ($marketPriceByHoldingId): float {
            $price = $marketPriceByHoldingId->get($holdingId);

            return is_numeric($price) ? $pieces * (float) $price : 0.0;
        });
    }

    /**
     * @return Collection<int, float>
     */
    private function openPiecesByHoldingIdAt(Depot $depot, Carbon $cutoff): Collection
    {
        return DepotTransaction::query()
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
    }

    /**
     * @param  Collection<int, int|string>  $holdingIds
     * @return Collection<int, string>
     */
    private function historicalMarketPriceByHoldingId(Collection $holdingIds, Carbon $cutoff): Collection
    {
        if ($holdingIds->isEmpty()) {
            return collect();
        }

        $holdings = StockHolding::query()
            ->whereKey($holdingIds->all())
            ->get(['id', 'isin', 'wkn', 'symbol', 'mic_code', 'exchange'])
            ->keyBy('id');
        $instrumentKeyByHoldingId = $holdings->mapWithKeys(fn (StockHolding $holding): array => [
            $holding->id => $this->stockPriceCatalog->instrumentKeyForHolding($holding),
        ]);
        $dailyPriceByHoldingId = StockHoldingDailyPrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->where('trading_date', '<=', $cutoff->toDateString())
            ->where(function ($query): void {
                $query
                    ->whereNotNull('adjusted_close')
                    ->orWhereNotNull('close');
            })
            ->orderByDesc('trading_date')
            ->orderByDesc('id')
            ->get(['stock_holding_id', 'trading_date', 'adjusted_close', 'close'])
            ->groupBy('stock_holding_id')
            ->map(fn (Collection $dailyPrices): ?StockHoldingDailyPrice => $dailyPrices->first());

        $realtimePriceByHoldingId = StockRealtimePrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->whereNotNull('price')
            ->where('as_of', '<=', $cutoff)
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->get(['stock_holding_id', 'price', 'as_of'])
            ->groupBy('stock_holding_id')
            ->map(fn (Collection $realtimePrices): ?StockRealtimePrice => $realtimePrices->first());
        $officialPriceByInstrumentKey = StockPrice::query()
            ->whereIn('instrument_key', $instrumentKeyByHoldingId->values()->all())
            ->where('source_key', 'eodhd_eod')
            ->where('price_type', 'historical_eod')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '<=', $cutoff)
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->get(['id', 'instrument_key', 'price', 'as_of'])
            ->groupBy('instrument_key')
            ->map(fn (Collection $stockPrices): ?StockPrice => $stockPrices->first());

        return $holdingIds
            ->mapWithKeys(function (int|string $holdingId) use ($dailyPriceByHoldingId, $instrumentKeyByHoldingId, $officialPriceByInstrumentKey, $realtimePriceByHoldingId): array {
                $dailyPrice = $dailyPriceByHoldingId->get($holdingId);
                $realtimePrice = $realtimePriceByHoldingId->get($holdingId);
                $officialPrice = $officialPriceByInstrumentKey->get($instrumentKeyByHoldingId->get($holdingId));
                $price = $this->historicalMarketPrice($dailyPrice, $realtimePrice, $officialPrice);

                return $price === null ? [] : [(int) $holdingId => $price];
            });
    }

    private function historicalMarketPrice(
        ?StockHoldingDailyPrice $dailyPrice,
        ?StockRealtimePrice $realtimePrice,
        ?StockPrice $officialPrice = null,
    ): ?string {
        $realtimePriceDate = $realtimePrice?->as_of?->copy()->startOfDay();
        $dailyPriceDate = $dailyPrice?->trading_date?->copy()->startOfDay();
        $officialPriceDate = $officialPrice?->as_of?->copy()->startOfDay();

        if ($officialPrice?->price !== null && ($dailyPriceDate === null || $officialPriceDate?->gte($dailyPriceDate))) {
            $dailyPrice = null;
            $dailyPriceDate = $officialPriceDate;
        }

        if ($realtimePrice?->price !== null && ($dailyPriceDate === null || $realtimePriceDate?->gt($dailyPriceDate))) {
            return $realtimePrice->price;
        }

        if ($officialPrice?->price !== null && $officialPriceDate?->equalTo($dailyPriceDate)) {
            return $officialPrice->price;
        }

        return $dailyPrice?->adjusted_close ?? $dailyPrice?->close;
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
        $realtimePricesByHoldingId = $this->realtimePricesByHoldingId($stockTransactionsByHoldingId->keys());
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
                : $this->stockMarketBalanceFromCollections(
                    $stockTransactionsByHoldingId,
                    $dailyPricesByHoldingId,
                    $realtimePricesByHoldingId,
                    $cutoff,
                );

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
     * @param  Collection<int, int|string>  $holdingIds
     * @return Collection<int, Collection<int, StockRealtimePrice>>
     */
    private function realtimePricesByHoldingId(Collection $holdingIds): Collection
    {
        if ($holdingIds->isEmpty()) {
            return collect();
        }

        return StockRealtimePrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->whereNotNull('price')
            ->where('as_of', '<=', now()->endOfDay())
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->get(['id', 'stock_holding_id', 'price', 'as_of'])
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
     * @param  Collection<int, Collection<int, StockRealtimePrice>>  $realtimePricesByHoldingId
     */
    private function stockMarketBalanceFromCollections(
        Collection $stockTransactionsByHoldingId,
        Collection $dailyPricesByHoldingId,
        Collection $realtimePricesByHoldingId,
        Carbon $cutoff,
    ): float {
        return $stockTransactionsByHoldingId->sum(function (Collection $transactions, int $holdingId) use ($dailyPricesByHoldingId, $realtimePricesByHoldingId, $cutoff): float {
            $openPieces = collect($this->openBuyLots(
                $transactions->filter(fn (DepotTransaction $transaction): bool => $transaction->booked_at?->lte($cutoff) ?? false),
            ))->sum(fn (array $lot): float => $lot['pieces']);

            if ($openPieces <= 0) {
                return 0.0;
            }

            $dailyPrice = $dailyPricesByHoldingId
                ->get($holdingId, collect())
                ->first(fn (StockHoldingDailyPrice $price): bool => $price->trading_date->lte($cutoff));
            $realtimePrice = $realtimePricesByHoldingId
                ->get($holdingId, collect())
                ->first(fn (StockRealtimePrice $price): bool => $price->as_of?->lte($cutoff) ?? false);
            $price = $this->historicalMarketPrice($dailyPrice, $realtimePrice);

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
            'stock_label' => $holding === null
                ? null
                : collect([$holding->name ?? $holding->symbol, $holding->subtitle])->filter()->implode(' · '),
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
