<?php

namespace App\Services;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DepotBalanceHistory
{
    public function __construct(private StoredHistoricalPriceResolver $historicalPrices) {}

    /**
     * @param  Collection<int, DepotTransaction>  $transactions
     * @return array<int, array{pieces: float, total_amount: float, booked_at: ?Carbon}>
     */
    public function openBuyLots(Collection $transactions): array
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

    public function performanceAccountBalanceAt(Depot $depot, Carbon $cutoff, ?array &$sourceMarkers = null, ?bool $freeOnly = null): ?float
    {
        $stockBalance = $this->stockMarketBalanceAt($depot, $cutoff, $sourceMarkers, $freeOnly);

        if ($stockBalance === null) {
            return null;
        }

        return $this->cashBalanceAt($depot, $cutoff) + $stockBalance;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $liveHoldings
     * @return array{stock_balance: ?float, cash_balance: float, account_balance: ?float, source_markers: list<string>}
     */
    public function tradingAccountValuesAt(Depot $depot, Carbon $cutoff, ?array $liveHoldings = null, ?bool $freeOnly = true): array
    {
        $stockBalance = $this->stockMarketBalanceAt($depot, $cutoff, $sources, $freeOnly, euroOnly: true, liveHoldings: $liveHoldings);
        $cashBalance = $this->cashBalanceAt($depot, $cutoff, includeFutureBookingsInOpening: true);

        return [
            'stock_balance' => $stockBalance,
            'cash_balance' => $cashBalance,
            'account_balance' => $stockBalance === null ? null : $stockBalance + $cashBalance,
            'source_markers' => $sources,
        ];
    }

    public function cashBalanceAt(Depot $depot, Carbon $cutoff, bool $includeFutureBookingsInOpening = false): float
    {
        return $this->initialCashBalance($depot, $includeFutureBookingsInOpening) + (float) DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->where('booked_at', '<=', $cutoff)
            ->sum('cash_delta');
    }

    public function initialCashBalance(Depot $depot, bool $includeFutureBookings = false): float
    {
        if (DepotTransaction::query()->where('depot_id', $depot->id)->where('type', 'opening_balance')
            ->where('booked_at', '<=', now()->endOfDay())->exists()) {
            return 0.0;
        }

        $cashDelta = (float) DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->when(! $includeFutureBookings, fn ($query) => $query->where('booked_at', '<=', now()->endOfDay()))
            ->sum('cash_delta');

        return round((float) $depot->account_balance - $cashDelta, 2);
    }

    public function externalCashFlowAfter(Depot $depot, Carbon $cutoff, ?Carbon $cashFlowEnd = null): float
    {
        return (float) DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereIn('type', DepotTransaction::ExternalCashflowTypes)
            ->where('booked_at', '>', $cutoff)
            ->where('booked_at', '<=', $cashFlowEnd ?? now()->endOfDay())
            ->sum('cash_delta');
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $liveHoldings
     */
    private function stockMarketBalanceAt(Depot $depot, Carbon $cutoff, ?array &$sourceMarkers, ?bool $freeOnly, bool $euroOnly = false, ?array $liveHoldings = null): ?float
    {
        $sourceMarkers = [];
        $openPiecesByHoldingId = $this->openPiecesByHoldingIdAt($depot, $cutoff);

        if ($openPiecesByHoldingId->isEmpty()) {
            return 0.0;
        }

        $marketPriceByHoldingId = $liveHoldings === null
            ? $this->historicalMarketPriceByHoldingId($openPiecesByHoldingId->keys(), $cutoff, $sources, $freeOnly, $euroOnly)
            : $this->liveMarketPriceByHoldingId($openPiecesByHoldingId->keys(), $cutoff, $liveHoldings, $sources);

        if ($openPiecesByHoldingId->keys()->contains(fn ($holdingId): bool => ! is_numeric($marketPriceByHoldingId->get($holdingId)))) {
            return null;
        }

        return $openPiecesByHoldingId->sum(function (float $pieces, int $holdingId) use ($marketPriceByHoldingId, $sources, &$sourceMarkers): float {
            $price = $marketPriceByHoldingId->get($holdingId);
            if (is_numeric($price) && $pieces > 0) {
                $sourceMarkers = MarketDataProvenance::merge($sourceMarkers, $sources[$holdingId] ?? []);
            }

            return is_numeric($price) ? $pieces * (float) $price : 0.0;
        });
    }

    /**
     * @param  Collection<int, int|string>  $holdingIds
     * @param  array<int, array<string, mixed>>  $liveHoldings
     * @return Collection<int, ?string>
     */
    private function liveMarketPriceByHoldingId(Collection $holdingIds, Carbon $cutoff, array $liveHoldings, ?array &$sources): Collection
    {
        $quotes = collect($liveHoldings)->keyBy('id');

        return StockHolding::query()->whereKey($holdingIds->all())->where('currency', 'EUR')->get()
            ->mapWithKeys(function (StockHolding $holding) use ($cutoff, $quotes, &$sources): array {
                $quote = $quotes->get($holding->id);

                if (($quote['latest_price_trading_date'] ?? null) !== $this->historicalPrices->tradingDate($holding, $cutoff)) {
                    return [$holding->id => null];
                }

                $sources[$holding->id] = $quote['latest_price_source_markers'];

                return [$holding->id => $quote['latest_price']];
            });
    }

    /**
     * @return Collection<int, float>
     */
    public function openPiecesByHoldingIdAt(Depot $depot, Carbon $cutoff): Collection
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

    /** @return Collection<int, StockHolding> */
    public function missingHoldingsAt(Depot $depot, Carbon $cutoff): Collection
    {
        return $this->historicalHoldings($this->openPiecesByHoldingIdAt($depot, $cutoff)->keys(), $cutoff, freeOnly: true)
            ->filter(fn (StockHolding $holding): bool => $this->historicalPrices->at($holding, $cutoff) === null);
    }

    /**
     * @param  Collection<int, int|string>  $holdingIds
     * @return Collection<int, string>
     */
    private function historicalMarketPriceByHoldingId(Collection $holdingIds, Carbon $cutoff, ?array &$sources, ?bool $freeOnly, bool $euroOnly = false): Collection
    {
        return $this->historicalHoldings($holdingIds, $cutoff, $freeOnly, $euroOnly)->mapWithKeys(function (StockHolding $holding) use ($cutoff, &$sources): array {
            $observation = $this->historicalPrices->at($holding, $cutoff);
            $price = $observation['price'] ?? null;
            $sources[$holding->id] = $observation['source_markers'] ?? [];

            return [$holding->id => $price];
        });
    }

    /** @return Collection<int, StockHolding> */
    private function historicalHoldings(Collection $holdingIds, Carbon $cutoff, ?bool $freeOnly, bool $euroOnly = false): Collection
    {
        if ($holdingIds->isEmpty()) {
            return collect();
        }

        $holdings = StockHolding::query()->whereKey($holdingIds->all())
            ->when($euroOnly, fn ($query) => $query->where('currency', 'EUR'))
            ->get();
        if ($holdings->isEmpty()) {
            return $holdings;
        }

        $this->historicalPrices->prime($holdings, $cutoff->copy()->subDays(14), $cutoff, $freeOnly);

        return $holdings;
    }
}
