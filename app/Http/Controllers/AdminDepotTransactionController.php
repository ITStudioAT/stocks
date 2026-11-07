<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Services\DashboardPerformanceRepair;
use App\Services\DashboardPerformanceSnapshots;
use App\Services\DepotBalanceHistory;
use App\Services\DepotTransactionBooker;
use App\Services\FreeTradingCalendar;
use App\Services\MarketDataProvenance;
use App\Services\MarketPriceStore;
use App\Services\StockPreviousCloseResolver;
use App\Services\StockPriceCatalog;
use App\Services\StoredHistoricalPriceResolver;
use App\Services\UiPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminDepotTransactionController extends Controller
{
    private const PerformancePeriods = ['week', 'last_week', 'month', 'last_month', 'year'];

    public function __construct(
        private StockPreviousCloseResolver $stockPreviousCloseResolver,
        private StockPriceCatalog $stockPriceCatalog,
        private StoredHistoricalPriceResolver $historicalPrices,
        private DepotBalanceHistory $balanceHistory,
        private DashboardPerformanceSnapshots $performanceSnapshots,
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
        return response()->json($this->performancePayload($this->activeDepot()));
    }

    public function repairPerformance(Request $request, DashboardPerformanceRepair $repair): JsonResponse
    {
        $target = $request->validate([
            'date' => ['required_without:period', 'prohibits:period', 'date_format:Y-m-d'],
            'period' => ['required_without:date', 'prohibits:date', Rule::in(self::PerformancePeriods)],
        ]);
        $depot = $this->activeDepotOrFail();
        $payload = $this->performancePayload($depot);
        $card = $this->performanceCard($payload, $target);
        if (! ($card['repairable'] ?? false)) {
            return response()->json(['status' => 'not_needed', 'message' => 'Für diese Karte ist keine Reparatur nötig.', ...$payload]);
        }

        [$dates, $completedPeriod] = $this->performanceRepairDates($target, $card);

        return $this->performanceRepairResponse($depot, $repair->start($depot, $target, $dates, $completedPeriod));
    }

    public function performanceRepairStatus(string $runId, DashboardPerformanceRepair $repair): JsonResponse
    {
        $depot = $this->activeDepotOrFail();
        $run = $repair->find($depot->id, $runId);
        abort_if($run === null, 404, 'Dieser Reparaturauftrag ist abgelaufen oder gehört zu einem anderen Depot.');

        return $this->performanceRepairResponse($depot, $run);
    }

    private function performancePayload(?Depot $depot): array
    {
        if (! $depot) {
            return ['days' => [], 'sums' => []];
        }

        $depotHoldings = $this->dashboardHoldings($depot);
        $stockBalance = $this->holdingStockBalance($depotHoldings, 'latest_price');
        $closing = [
            'balance' => $stockBalance === null ? null : (float) $depot->account_balance + $stockBalance,
            'source_markers' => $this->currentHoldingSources($depotHoldings),
        ];

        return [
            'days' => $this->dailyPerformancePayloads($depot, $depotHoldings, $closing),
            'sums' => $this->dashboardPerformanceSumPayloads($depot, $closing),
        ];
    }

    private function performanceCard(array $payload, array $target): ?array
    {
        $key = array_key_first($target);

        return collect($payload[$key === 'date' ? 'days' : 'sums'])->firstWhere($key, $target[$key]);
    }

    private function performanceRepairDates(array $target, array $card): array
    {
        if (isset($target['date'])) {
            $date = Carbon::parse($target['date']);
            $dates = [$date->copy()->subDay()->toDateString()];
            if (! $card['is_live']) {
                $dates[] = $date->toDateString();
            }

            return [$dates, null];
        }

        $period = $target['period'];
        $today = now()->startOfDay();
        $completedPeriod = str_starts_with($period, 'last_');
        $period = $completedPeriod ? substr($period, 5) : $period;
        $end = $completedPeriod ? $this->performanceSnapshots->periodOpening($period, $today) : $today;
        $dates = [$this->performanceSnapshots->periodOpening($period, $end)->toDateString()];
        if ($completedPeriod) {
            $dates[] = $end->toDateString();
        }

        return [$dates, $completedPeriod ? $period : null];
    }

    private function performanceRepairResponse(Depot $depot, array $run): JsonResponse
    {
        $status = $run['status'];
        if (in_array($status, ['queued', 'running'], true)) {
            return response()->json(['status' => $status, 'run_id' => $run['id']]);
        }
        if ($status === 'in_progress') {
            return response()->json(['status' => $status, 'message' => 'Für dieses Depot läuft bereits eine Reparatur.']);
        }

        $payload = $this->performancePayload($depot);
        $card = isset($run['target']) ? $this->performanceCard($payload, $run['target']) : null;
        if (($card['change_amount'] ?? null) !== null) {
            return response()->json(['status' => 'repaired', 'message' => 'Depotentwicklung neu berechnet.', ...$payload]);
        }

        return $status === 'failed'
            ? response()->json(['status' => 'failed', 'message' => 'Der kostenlose Kursabruf ist fehlgeschlagen oder pausiert. Bitte später erneut versuchen.', ...$payload])
            : response()->json(['status' => 'unavailable', 'message' => 'Für diese Karte fehlen weiterhin verwendbare Kurse aus kostenlosen Quellen.', ...$payload]);
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

        DB::transaction(function () use ($depot, $depotTransaction, $validated): void {
            Depot::query()->lockForUpdate()->findOrFail($depot->id);
            $depotTransaction->refresh();
            $originalDate = $depotTransaction->booked_at;
            $date = Carbon::createFromFormat('Y-m-d', $validated['booked_at'])->startOfDay();
            $depotTransaction->update(['booked_at' => $date]);

            if ($depotTransaction->wasChanged('booked_at')) {
                $futureBoundary = now()->endOfDay();
                $affectedDate = $originalDate->gt($futureBoundary) !== $date->gt($futureBoundary)
                    ? null
                    : $originalDate->min($date);
                $this->performanceSnapshots->invalidate($depot->id, $affectedDate);
            }
        }, attempts: 3);

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

    /** @return list<array{id: int, position_pieces: string, latest_price: ?string, latest_price_source_markers: list<string>}> */
    private function dashboardHoldings(Depot $depot): array
    {
        $positions = $depot->transactions()
            ->whereNotNull('stock_holding_id')
            ->whereIn('type', DepotTransaction::StockTypes)
            ->selectRaw('stock_holding_id, SUM(CASE WHEN type = ? THEN pieces ELSE -pieces END) AS position_pieces', ['buy'])
            ->groupBy('stock_holding_id')
            ->pluck('position_pieces', 'stock_holding_id')
            ->map(fn ($pieces): string => number_format((float) $pieces, 8, '.', ''))
            ->filter(fn (string $pieces): bool => (float) $pieces > 0);

        if ($positions->isEmpty()) {
            return [];
        }

        $holdings = StockHolding::query()->whereKey($positions->keys()->all())->get();
        $this->stockPreviousCloseResolver->prime($holdings);

        return $holdings->map(function (StockHolding $holding) use ($positions): array {
            $storedPrice = $this->latestStoredPrice($holding);
            $price = $this->latestHoldingPrice($holding, $storedPrice);

            return [
                'id' => $holding->id,
                'position_pieces' => $positions->get($holding->id),
                'latest_price' => $price,
                'latest_price_source_markers' => $this->latestHoldingPriceSources($holding, $storedPrice, $price),
            ];
        })->all();
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

        $this->stockPreviousCloseResolver->prime($holdings);

        return $holdings
            ->map(fn (StockHolding $holding): array => $this->depotHoldingPayload(
                holding: $holding,
                positionPieces: $positionPiecesByHoldingId->get($holding->id, '0.00000000'),
                yearStartPrice: $yearStartPriceByHoldingId->get($holding->id),
            ))
            ->all();
    }

    private function storedPriceDate(StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $stockPrice): ?Carbon
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
                $openLots = collect($this->balanceHistory->openBuyLots($transactions))
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
     * @return array{id: int, symbol: ?string, name: ?string, subtitle: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}
     */
    private function depotHoldingPayload(
        StockHolding $holding,
        string $positionPieces,
        ?string $yearStartPrice,
    ): array {
        $latestStoredPrice = $this->latestStoredPrice($holding);
        $latestPrice = $this->latestHoldingPrice($holding, $latestStoredPrice);
        $previousCloses = $this->stockPreviousCloseResolver->forObservation($holding, $latestStoredPrice);
        $changeReference = $this->stockPreviousCloseResolver->comparisonReference($holding, $latestStoredPrice ?? $holding->latest_price_as_of, $previousCloses['previous_close'], $previousCloses['previous_close_date']);

        $previousDayChange = $this->priceChangePercent($latestPrice, $changeReference);

        return [
            'id' => $holding->id,
            'symbol' => $holding->symbol,
            'name' => $holding->name,
            'subtitle' => $holding->subtitle,
            'isin' => $holding->isin,
            'currency' => $latestStoredPrice?->currency ?? $holding->currency,
            'latest_price' => $latestPrice,
            'latest_price_source_markers' => $this->latestHoldingPriceSources($holding, $latestStoredPrice, $latestPrice),
            'previous_day_price' => $previousCloses['previous_close'],
            'previous_day_price_source_markers' => $previousCloses['previous_close_source_markers'],
            'previous_day_change_percent_source_markers' => $previousDayChange === null ? [] : MarketDataProvenance::merge($previousCloses['previous_close_source_markers'], $latestStoredPrice === null ? MarketDataProvenance::markers($holding->latest_price_source) : MarketDataProvenance::forRecord($latestStoredPrice)),
            'previous_day_price_date' => $previousCloses['previous_close_date'],
            'previous_day_change_percent' => $previousDayChange,
            'flatex_price' => $holding->flatex_price,
            'year_start_price' => $yearStartPrice,
            'latest_price_fetched_at' => $latestStoredPrice?->fetched_at?->toIso8601String() ?? $holding->latest_price_fetched_at?->toIso8601String(),
            'latest_price_status' => $this->latestPriceStatus($holding, $latestStoredPrice, $latestPrice),
            'position_pieces' => $positionPieces,
        ];
    }

    private function latestStoredPrice(StockHolding $holding): StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null
    {
        $latest = $this->stockPreviousCloseResolver->latestObservation($holding);

        if ($latest instanceof StockHoldingIntradayCandle) {
            $latest->setAttribute('price', $latest->close);
            $latest->setAttribute('fetched_at', $latest->updated_at ?? $latest->created_at);
        }

        return $latest;
    }

    private function latestHoldingPrice(StockHolding $holding, StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $record): ?string
    {
        return $record?->price ?? (MarketDataProvenance::markers($holding->latest_price_source) === ['free'] ? $holding->latest_price : null);
    }

    /** @return list<string> */
    private function latestHoldingPriceSources(StockHolding $holding, StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $record, ?string $price): array
    {
        if ($price === null) {
            return [];
        }

        return $record === null
            ? MarketDataProvenance::markers($holding->latest_price_source)
            : MarketDataProvenance::forRecord($record);
    }

    private function priceChangePercent(?string $currentPrice, ?string $referencePrice): ?string
    {
        if (! is_numeric($currentPrice) || ! is_numeric($referencePrice) || (float) $referencePrice === 0.0) {
            return null;
        }

        return $this->decimal((((float) $currentPrice - (float) $referencePrice) / (float) $referencePrice) * 100, 2);
    }

    private function latestPriceStatus(StockHolding $holding, StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $latestStoredPrice, ?string $latestPrice): string
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

    private function storedPriceStatus(StockPrice|StockRealtimePrice|StockHoldingIntradayCandle|null $stockPrice): ?string
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
        $previousDayCutoff = now()->subDay()->endOfDay();
        $previousDayBalance = $this->previousDayAccountBalance($depot, $previousDayCutoff, $previousSources);
        $previousDayExternalCashFlowAmount = $this->balanceHistory->externalCashFlowAfter($depot, $previousDayCutoff);
        $baselines = $this->depotValuationBaselines($depot);
        $baselines['previous_day_balance_source_markers'] = $previousSources;

        return [
            'latest' => $this->depotValuationPayload(
                $depotHoldings,
                'latest',
                $previousDayBalance,
                $previousDayExternalCashFlowAmount,
                $baselines,
            ),
            'flatex' => $this->depotValuationPayload(
                $depotHoldings,
                'flatex',
                $previousDayBalance,
                $previousDayExternalCashFlowAmount,
                $baselines,
            ),
        ];
    }

    /**
     * @return array{
     *     cash_balance: float,
     *     cash_flow_summary: array<string, float>,
     *     month_start_balance: float,
     *     one_week_start_balance: float,
     *     open_buy_lot_cost: float
     * }
     */
    private function depotValuationBaselines(Depot $depot): array
    {
        $cashBalance = (float) $depot->account_balance;
        $oneWeekStartCutoff = now()->startOfWeek()->subSecond();
        $monthStartCutoff = now()->startOfMonth()->subSecond();
        $yearStartCutoff = now()->startOfYear()->endOfDay();
        $weekBalance = $this->balanceHistory->performanceAccountBalanceAt($depot, $oneWeekStartCutoff, $weekSources);
        $monthBalance = $this->balanceHistory->performanceAccountBalanceAt($depot, $monthStartCutoff, $monthSources);
        $yearBalance = $this->balanceHistory->performanceAccountBalanceAt($depot, $yearStartCutoff, $yearSources);

        return [
            'cash_balance' => $cashBalance,
            'cash_flow_summary' => $this->cashFlowSummary($depot, $cashBalance, now()->endOfDay()),
            'one_week_start_balance' => $weekBalance,
            'one_week_start_balance_source_markers' => $weekSources,
            'month_start_balance' => $monthBalance,
            'month_start_balance_source_markers' => $monthSources,
            'year_start_balance' => $yearBalance,
            'year_start_balance_source_markers' => $yearSources,
            'one_week_external_cash_flow_amount' => $this->balanceHistory->externalCashFlowAfter($depot, $oneWeekStartCutoff),
            'month_external_cash_flow_amount' => $this->balanceHistory->externalCashFlowAfter($depot, $monthStartCutoff),
            'year_external_cash_flow_amount' => $this->balanceHistory->externalCashFlowAfter($depot, $yearStartCutoff),
            'year_broker_bonus_amount' => (float) DepotTransaction::query()->where('depot_id', $depot->id)
                ->where('type', 'broker_bonus')->where('booked_at', '>', $yearStartCutoff)
                ->where('booked_at', '<=', now()->endOfDay())->sum('cash_delta'),
            'open_buy_lot_cost' => $this->openBuyLotCostByHoldingId($depot, now()->endOfDay())->sum(),
        ];
    }

    /**
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $depotHoldings
     * @param  array{cash_balance: float, cash_flow_summary: array<string, float>, month_start_balance: float, one_week_start_balance: float, open_buy_lot_cost: float}  $baselines
     * @return array<string, string>
     */
    private function depotValuationPayload(
        array $depotHoldings,
        string $source,
        ?float $previousDayBalance,
        float $previousDayExternalCashFlowAmount,
        array $baselines,
    ): array {
        $stockBalance = $this->holdingStockBalance(
            $depotHoldings,
            $source === 'flatex' ? 'flatex_price' : 'latest_price',
        );
        $cashBalance = $baselines['cash_balance'];
        $currentBalance = $stockBalance === null ? null : $cashBalance + $stockBalance;
        $previousDayPerformanceBaseline = $previousDayBalance === null ? null : $previousDayBalance + $previousDayExternalCashFlowAmount;
        $previousDayChangeAmount = $previousDayPerformanceBaseline === null ? null : $currentBalance - $previousDayPerformanceBaseline;
        $previousDayChangePercent = $previousDayPerformanceBaseline === null || $previousDayPerformanceBaseline === 0.0
            ? null
            : ($previousDayChangeAmount / $previousDayPerformanceBaseline) * 100;
        $cashFlowSummary = $baselines['cash_flow_summary'];
        $yearStartBalance = $baselines['year_start_balance'];
        $yearCapital = $yearStartBalance === null ? null : $yearStartBalance + $baselines['year_external_cash_flow_amount'];
        $balanceChangeWithBrokerBonusAmount = $currentBalance === null || $yearCapital === null ? null : $currentBalance - $yearCapital;
        $balanceChangeAmount = $balanceChangeWithBrokerBonusAmount === null ? null : $balanceChangeWithBrokerBonusAmount - $baselines['year_broker_bonus_amount'];
        $oneWeekStartBalance = $baselines['one_week_start_balance'];
        $monthStartBalance = $baselines['month_start_balance'];
        $balanceChangePercent = $yearCapital === null || $yearCapital <= 0 || $balanceChangeAmount === null
            ? null
            : ($balanceChangeAmount / $yearCapital) * 100;
        $monthCapital = $monthStartBalance === null ? null : $monthStartBalance + $baselines['month_external_cash_flow_amount'];
        $weekCapital = $oneWeekStartBalance === null ? null : $oneWeekStartBalance + $baselines['one_week_external_cash_flow_amount'];
        $monthChangeAmount = $monthCapital === null || $currentBalance === null ? null : $currentBalance - $monthCapital;
        $monthChangePercent = $monthCapital === null || $monthCapital <= 0 || $monthChangeAmount === null
            ? null
            : ($monthChangeAmount / $monthCapital) * 100;
        $oneWeekChangeAmount = $weekCapital === null || $currentBalance === null ? null : $currentBalance - $weekCapital;
        $oneWeekChangePercent = $weekCapital === null || $weekCapital <= 0 || $oneWeekChangeAmount === null
            ? null
            : ($oneWeekChangeAmount / $weekCapital) * 100;
        $taxableStockGainAmount = $this->taxableStockGainAmount(
            $depotHoldings,
            $source,
            $baselines['open_buy_lot_cost'],
        );

        $payload = [
            'stock_balance' => $this->decimal($stockBalance, 2),
            'cash_balance' => $this->decimal($cashBalance, 2),
            'account_balance' => $this->decimal($stockBalance === null ? null : $cashBalance + $stockBalance, 2),
            'previous_day_balance' => $this->decimal($previousDayBalance, 2),
            'previous_day_external_cash_flow_amount' => $this->decimal($previousDayExternalCashFlowAmount, 2),
            'previous_day_change_amount' => $this->decimal($previousDayChangeAmount, 2),
            'previous_day_change_percent' => $this->decimal($previousDayChangePercent, 2),
            'year_start_balance' => $this->decimal($yearStartBalance, 2),
            'year_net_capital' => $this->decimal($yearCapital, 2),
            'current_balance' => $this->decimal($currentBalance, 2),
            'balance_change_amount' => $this->decimal($balanceChangeAmount, 2),
            'balance_change_percent' => $this->decimal($balanceChangePercent, 2),
            'balance_change_with_broker_bonus_amount' => $this->decimal($balanceChangeWithBrokerBonusAmount, 2),
            'balance_change_with_broker_bonus_percent' => $this->decimal($yearCapital === null || $yearCapital <= 0 || $balanceChangeWithBrokerBonusAmount === null ? null : ($balanceChangeWithBrokerBonusAmount / $yearCapital) * 100, 2),
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
        if ($currentBalance === null) {
            foreach (['stock_balance', 'account_balance', 'current_balance', 'balance_change_amount', 'balance_change_percent', 'balance_change_with_broker_bonus_amount', 'balance_change_with_broker_bonus_percent', 'taxable_stock_gain_amount', 'previous_day_change_amount', 'previous_day_change_percent', 'month_change_amount', 'month_change_percent', 'one_week_change_amount', 'one_week_change_percent'] as $field) {
                $payload[$field] = null;
            }
        }

        $currentSources = $source === 'latest' ? $this->currentHoldingSources($depotHoldings) : [];
        foreach (['stock_balance', 'account_balance', 'current_balance', 'balance_change_amount', 'balance_change_percent', 'balance_change_with_broker_bonus_amount', 'balance_change_with_broker_bonus_percent', 'taxable_stock_gain_amount'] as $field) {
            $payload[$field.'_source_markers'] = $currentSources;
        }
        $payload['year_start_balance_source_markers'] = $baselines['year_start_balance_source_markers'];
        foreach (['balance_change_amount', 'balance_change_percent', 'balance_change_with_broker_bonus_amount', 'balance_change_with_broker_bonus_percent'] as $field) {
            $payload[$field.'_source_markers'] = MarketDataProvenance::merge($currentSources, $baselines['year_start_balance_source_markers']);
        }
        foreach (['previous_day', 'one_week', 'month'] as $period) {
            $balanceField = $period === 'previous_day' ? 'previous_day_balance' : $period.'_start_balance';
            $baselineSources = $baselines[$balanceField.'_source_markers'] ?? [];
            $payload[$balanceField.'_source_markers'] = $baselineSources;
            $payload[$period.'_change_amount_source_markers'] = MarketDataProvenance::merge($currentSources, $baselineSources);
            $payload[$period.'_change_percent_source_markers'] = $payload[$period.'_change_amount_source_markers'];
        }

        foreach ($payload as $key => $value) {
            if (str_ends_with($key, '_source_markers') && array_key_exists(substr($key, 0, -15), $payload) && $payload[substr($key, 0, -15)] === null) {
                $payload[$key] = [];
            }
        }

        return $payload;
    }

    private function previousDayAccountBalance(Depot $depot, Carbon $previousDayCutoff, ?array &$sourceMarkers = null): ?float
    {
        $sourceMarkers = [];
        $openPiecesByHoldingId = $this->balanceHistory->openPiecesByHoldingIdAt($depot, $previousDayCutoff);
        $complete = true;
        $holdings = StockHolding::query()->whereKey($openPiecesByHoldingId->keys()->all())->get();
        $this->stockPreviousCloseResolver->prime($holdings);
        $stockBalance = $holdings->sum(function (StockHolding $holding) use ($openPiecesByHoldingId, $previousDayCutoff, &$sourceMarkers, &$complete): float {
            $previousClose = MarketPriceStore::enabled()
                ? ['previous_close' => $this->stockPreviousCloseResolver->priceAt($holding, $previousDayCutoff), 'previous_close_source_markers' => ['free']]
                : $this->stockPreviousCloseResolver->resolve($holding);
            $markers = $previousClose['previous_close_source_markers'];
            $previousClose = $previousClose['previous_close'];

            if (! is_numeric($previousClose)) {
                $complete = false;

                return 0.0;
            }

            $sourceMarkers = MarketDataProvenance::merge($sourceMarkers, $markers);

            return (float) $previousClose * $openPiecesByHoldingId->get($holding->id, 0.0);
        });

        return $complete ? $this->balanceHistory->cashBalanceAt($depot, $previousDayCutoff) + $stockBalance : null;
    }

    /**
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $depotHoldings
     * @param  array{balance: ?float, source_markers: list<string>}  $closing
     * @return array<int, array{date: string, is_live: bool, account_balance: ?string, previous_balance: ?string, change_amount: ?string, change_percent: ?string}>
     */
    private function dailyPerformancePayloads(Depot $depot, array $depotHoldings, array $closing): array
    {
        $holdingIds = collect($depotHoldings)->pluck('id');

        if ($holdingIds->isEmpty()) {
            return [];
        }

        $performanceDates = $this->currentWeekPerformanceDates();
        $holdings = StockHolding::query()->whereKey($holdingIds->all())
            ->get(['id', 'isin', 'wkn', 'symbol', 'mic_code', 'exchange', 'currency']);
        $latestPerformanceDate = $this->latestDailyPerformanceDate($holdings);

        $isLivePerformanceDate = $latestPerformanceDate !== null
            && $this->isLivePerformanceDate($holdingIds, $latestPerformanceDate);
        $today = now()->toDateString();
        $calendar = app(FreeTradingCalendar::class);
        $balanceDates = collect([$performanceDates->first()])
            ->map(fn (string $date): string => Carbon::parse($date)->subDay()->toDateString())
            ->merge($performanceDates)
            ->filter(fn (string $date): bool => $latestPerformanceDate !== null && $date <= $latestPerformanceDate);
        $balances = $balanceDates->mapWithKeys(fn (string $date): array => [
            $date => $isLivePerformanceDate && $date === $latestPerformanceDate
                ? $closing
                : $this->performanceSnapshots->closingBalance($depot, Carbon::parse($date)),
        ]);

        return $performanceDates
            ->map(function (string $date) use ($depot, $balances, $isLivePerformanceDate, $latestPerformanceDate, $today, $calendar, $holdings): array {
                if ($date > $today || $latestPerformanceDate === null || $date > $latestPerformanceDate) {
                    return [
                        'date' => $date,
                        'is_live' => false,
                        'account_balance' => null,
                        'previous_balance' => null,
                        'change_amount' => null,
                        'change_percent' => null,
                        'repairable' => $date <= $today
                            && $holdings->contains(fn (StockHolding $holding): bool => $calendar->sessionHasSettled($holding, $date)),
                    ];
                }

                $previousDate = Carbon::parse($date)->subDay()->toDateString();
                $accountBalance = $balances->get($date)['balance'] ?? null;
                $previousBalance = $balances->get($previousDate)['balance'] ?? null;
                $accountSources = $balances->get($date)['source_markers'] ?? [];
                $previousSources = $balances->get($previousDate)['source_markers'] ?? [];
                $flows = $this->balanceHistory->externalCashFlowAfter($depot, Carbon::parse($previousDate)->endOfDay(), Carbon::parse($date)->endOfDay());
                $capital = $previousBalance === null ? null : $previousBalance + $flows;
                $changeAmount = $accountBalance === null || $capital === null ? null : $accountBalance - $capital;
                $changePercent = $capital === null || $capital <= 0 || $changeAmount === null
                    ? null
                    : ($changeAmount / $capital) * 100;

                return [
                    'date' => $date,
                    'is_live' => $isLivePerformanceDate && $date === $latestPerformanceDate,
                    'account_balance' => $this->decimal($accountBalance, 2),
                    'previous_balance' => $this->decimal($previousBalance, 2),
                    'account_balance_source_markers' => $accountSources,
                    'previous_balance_source_markers' => $previousSources,
                    'change_amount_source_markers' => $changeAmount === null ? [] : MarketDataProvenance::merge($accountSources, $previousSources),
                    'change_percent_source_markers' => $changePercent === null ? [] : MarketDataProvenance::merge($accountSources, $previousSources),
                    'change_amount' => $this->decimal($changeAmount, 2),
                    'change_percent' => $changePercent === null ? null : $this->decimal($changePercent, 2),
                    'repairable' => $changeAmount === null,
                ];
            })
            ->all();
    }

    /**
     * @param  array{balance: ?float, source_markers: list<string>}  $closing
     * @return list<array{period: string, source_markers: list<string>, change_amount: ?string, change_percent: ?string}>
     */
    private function dashboardPerformanceSumPayloads(Depot $depot, array $closing): array
    {
        $today = now()->startOfDay();
        $weekOpening = $this->performanceSnapshots->periodOpening('week', $today);
        $monthOpening = $this->performanceSnapshots->periodOpening('month', $today);
        $yearOpening = $this->performanceSnapshots->periodOpening('year', $today);

        return array_map(fn (array $card): array => [...$card, 'repairable' => $card['change_amount'] === null], [
            $this->performanceSnapshots->periodPerformance($depot, 'week', $weekOpening, $today, $closing),
            [...$this->performanceSnapshots->completedPeriod($depot, 'week', $weekOpening), 'period' => 'last_week'],
            $this->performanceSnapshots->periodPerformance($depot, 'month', $monthOpening, $today, $closing),
            [...$this->performanceSnapshots->completedPeriod($depot, 'month', $monthOpening), 'period' => 'last_month'],
            $this->performanceSnapshots->periodPerformance($depot, 'year', $yearOpening, $today, $closing),
        ]);
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
     * @param  Collection<int, StockHolding>  $holdings
     */
    private function latestDailyPerformanceDate(Collection $holdings): ?string
    {
        $from = now()->startOfWeek(Carbon::MONDAY)->subDay()->startOfDay();
        $until = now()->endOfDay();
        $holdingIds = $holdings->pluck('id');
        $instrumentKeys = $holdings
            ->map(fn (StockHolding $holding): string => $this->stockPriceCatalog->instrumentKeyForHolding($holding))
            ->unique()
            ->values();
        $dailyPriceDate = StockHoldingDailyPrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->whereBetween('trading_date', [$from->toDateString(), $until->toDateString()])
            ->where(function ($query): void {
                $query
                    ->whereNotNull('adjusted_close')
                    ->orWhereNotNull('close');
            })
            ->max('trading_date');

        $realtimePriceDate = StockRealtimePrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->whereNotNull('price')
            ->whereBetween('as_of', [$from, $until])
            ->max('as_of');
        $officialPriceDate = StockPrice::query()
            ->whereIn('instrument_key', $instrumentKeys->all())
            ->where('source_key', 'eodhd_eod')
            ->where('price_type', 'historical_eod')
            ->whereNotNull('price')
            ->whereBetween('as_of', [$from, $until])
            ->max('as_of');

        return collect([$dailyPriceDate, $realtimePriceDate, $officialPriceDate])
            ->filter()
            ->map(fn ($date): string => substr((string) $date, 0, 10))
            ->max();
    }

    /**
     * @param  Collection<int, int|string>  $holdingIds
     */
    private function isLivePerformanceDate(Collection $holdingIds, string $date): bool
    {
        if ($date !== now()->toDateString()) {
            return false;
        }

        $start = Carbon::parse($date)->startOfDay();

        return StockRealtimePrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->where('as_of', '>=', $start)
            ->where('as_of', '<', $start->copy()->addDay())
            ->whereNotNull('price')
            ->whereIn('freshness_status', ['realtime', 'fresh', 'delayed'])
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $depotHoldings
     */
    private function holdingStockBalance(array $depotHoldings, string $priceKey): ?float
    {
        if (collect($depotHoldings)->contains(fn (array $holding): bool => (float) ($holding['position_pieces'] ?? 0) > 0 && ! is_numeric($holding[$priceKey] ?? null))) {
            return null;
        }

        return collect($depotHoldings)->sum(function (array $holding) use ($priceKey): float {
            $price = $holding[$priceKey] ?? null;
            $pieces = $holding['position_pieces'] ?? null;

            if (! is_numeric($price) || ! is_numeric($pieces)) {
                return 0.0;
            }

            return (float) $price * (float) $pieces;
        });
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
            ->map(fn (Collection $transactions): float => collect($this->balanceHistory->openBuyLots($transactions))
                ->sum(fn (array $lot): float => $lot['total_amount']));
    }

    /**
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $depotHoldings
     */
    private function taxableStockGainAmount(
        array $depotHoldings,
        string $source,
        float $openBuyLotCost,
    ): float {
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
        $initialCashBalance = $this->balanceHistory->initialCashBalance($depot);
        $stockTransactionsByHoldingId = $transactions
            ->whereNotNull('stock_holding_id')
            ->whereIn('type', DepotTransaction::StockTypes)
            ->groupBy('stock_holding_id');
        $holdings = StockHolding::query()->whereKey($stockTransactionsByHoldingId->keys()->all())->get()->keyBy('id');
        $this->historicalPrices->prime($holdings, $start->copy()->subDays(14), now());
        $externalCashFlows = $transactions->filter(fn (DepotTransaction $transaction): bool => in_array($transaction->type, DepotTransaction::ExternalCashflowTypes, true)
            && $transaction->booked_at?->gt($start->copy()->endOfDay()));
        $totalExternalCashFlow = (float) $externalCashFlows->sum('cash_delta');
        $points = [];

        for ($date = $start->copy(); $date->lte($today); $date->addDay()) {
            $cutoff = $date->copy()->endOfDay();
            $isToday = $date->isSameDay($today);
            $cashBalance = $isToday
                ? (float) $depot->account_balance
                : $this->cashBalanceFromTransactions($transactions, $cutoff, $initialCashBalance);
            $pointSources = $this->currentHoldingSources($currentDepotHoldings);
            $stockBalance = $isToday
                ? $this->currentStockBalance($currentDepotHoldings)
                : $this->stockMarketBalanceFromCollections(
                    $stockTransactionsByHoldingId,
                    $holdings,
                    $cutoff,
                    $pointSources,
                );

            $netExternalCashFlow = (float) $externalCashFlows->filter(fn (DepotTransaction $transaction): bool => $transaction->booked_at?->lte($cutoff))
                ->sum('cash_delta');
            $accountBalance = $stockBalance === null ? null : $cashBalance + $stockBalance;
            $points[] = [
                'date' => $date->toDateString(),
                'source_markers' => $stockBalance === null ? [] : $pointSources,
                'net_external_cash_flow' => $this->decimal($netExternalCashFlow, 2),
                'performance_balance' => $this->decimal($accountBalance === null ? null : $accountBalance + $totalExternalCashFlow - $netExternalCashFlow, 2),
                'stock_balance' => $this->decimal($stockBalance, 2),
                'cash_balance' => $this->decimal($cashBalance, 2),
                'account_balance' => $this->decimal($accountBalance, 2),
            ];
        }

        return $points;
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
     * @param  array<int, array{id: int, symbol: ?string, name: ?string, isin: ?string, currency: ?string, latest_price: ?string, previous_day_price: ?string, previous_day_price_date: ?string, previous_day_change_percent: ?string, flatex_price: ?string, year_start_price: ?string, latest_price_fetched_at: ?string, latest_price_status: string, position_pieces: string}>  $currentDepotHoldings
     */
    /** @param array<int, array<string, mixed>> $holdings @return array<int, string> */
    private function currentHoldingSources(array $holdings): array
    {
        $markers = [];
        foreach ($holdings as $holding) {
            if (is_numeric($holding['latest_price'] ?? null) && (float) ($holding['position_pieces'] ?? 0) > 0) {
                $markers = MarketDataProvenance::merge($markers, $holding['latest_price_source_markers'] ?? []);
            }
        }

        return $markers;
    }

    private function currentStockBalance(array $currentDepotHoldings): ?float
    {
        return $this->holdingStockBalance($currentDepotHoldings, 'latest_price');
    }

    /**
     * @param  Collection<int, Collection<int, DepotTransaction>>  $stockTransactionsByHoldingId
     * @param  Collection<int, StockHolding>  $holdings
     */
    private function stockMarketBalanceFromCollections(
        Collection $stockTransactionsByHoldingId,
        Collection $holdings,
        Carbon $cutoff,
        ?array &$sourceMarkers = null,
    ): ?float {
        $sourceMarkers = [];
        $balance = 0.0;

        foreach ($stockTransactionsByHoldingId as $holdingId => $transactions) {
            $pieces = collect($this->balanceHistory->openBuyLots($transactions->filter(fn (DepotTransaction $transaction): bool => $transaction->booked_at?->lte($cutoff) ?? false)))
                ->sum(fn (array $lot): float => $lot['pieces']);

            if ($pieces <= 0) {
                continue;
            }

            $holding = $holdings->get($holdingId);
            $observation = $holding === null ? null : $this->historicalPrices->at($holding, $cutoff);
            $price = $observation['price'] ?? null;

            if ($price === null) {
                return null;
            }

            $sourceMarkers = MarketDataProvenance::merge($sourceMarkers, $observation['source_markers']);
            $balance += $pieces * (float) $price;
        }

        return $balance;
    }

    private function decimal(?float $value, int $places): ?string
    {
        if ($value === null) {
            return null;
        }

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
