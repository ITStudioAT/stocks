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

    public function performanceAccountBalanceAt(Depot $depot, Carbon $cutoff, ?array &$sourceMarkers = null): ?float
    {
        $stockBalance = $this->stockMarketBalanceAt($depot, $cutoff, $sourceMarkers);

        if ($stockBalance === null) {
            return null;
        }

        return $this->cashBalanceAt($depot, $cutoff) + $stockBalance;
    }

    public function cashBalanceAt(Depot $depot, Carbon $cutoff): float
    {
        return $this->initialCashBalance($depot) + (float) DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->where('booked_at', '<=', $cutoff)
            ->sum('cash_delta');
    }

    public function initialCashBalance(Depot $depot): float
    {
        if (DepotTransaction::query()->where('depot_id', $depot->id)->where('type', 'opening_balance')
            ->where('booked_at', '<=', now()->endOfDay())->exists()) {
            return 0.0;
        }

        $cashDelta = (float) DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->where('booked_at', '<=', now()->endOfDay())
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

    private function stockMarketBalanceAt(Depot $depot, Carbon $cutoff, ?array &$sourceMarkers = null): ?float
    {
        $sourceMarkers = [];
        $openPiecesByHoldingId = $this->openPiecesByHoldingIdAt($depot, $cutoff);

        if ($openPiecesByHoldingId->isEmpty()) {
            return 0.0;
        }

        $marketPriceByHoldingId = $this->historicalMarketPriceByHoldingId($openPiecesByHoldingId->keys(), $cutoff, $sources);

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

    /**
     * @param  Collection<int, int|string>  $holdingIds
     * @return Collection<int, string>
     */
    private function historicalMarketPriceByHoldingId(Collection $holdingIds, Carbon $cutoff, ?array &$sources = null): Collection
    {
        if ($holdingIds->isEmpty()) {
            return collect();
        }

        $holdings = StockHolding::query()->whereKey($holdingIds->all())->get();
        $this->historicalPrices->prime($holdings, $cutoff->copy()->subDays(14), $cutoff);

        return $holdings->mapWithKeys(function (StockHolding $holding) use ($cutoff, &$sources): array {
            $observation = $this->historicalPrices->at($holding, $cutoff);
            $price = $observation['price'] ?? null;
            $sources[$holding->id] = $observation['source_markers'] ?? [];

            return [$holding->id => $price];
        });
    }
}
