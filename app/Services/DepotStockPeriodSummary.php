<?php

namespace App\Services;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockRealtimePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DepotStockPeriodSummary
{
    /**
     * @return array{
     *     period: string,
     *     label: string,
     *     year: ?int,
     *     stocks: array<int, array<string, int|string|null>>
     * }
     */
    public function payload(?Depot $depot, string $period): array
    {
        $definition = $this->periodDefinition($period);

        return [
            'period' => $definition['period'],
            'label' => $definition['label'],
            'year' => $definition['year'],
            'stocks' => $depot === null ? [] : $this->stocksForDepot($depot, $definition),
        ];
    }

    /**
     * @param  array{
     *     period: string,
     *     label: string,
     *     year: ?int,
     *     start: ?Carbon,
     *     cutoff: Carbon,
     *     uses_current_price: bool
     * }  $definition
     * @return array<int, array<string, int|string|null>>
     */
    private function stocksForDepot(Depot $depot, array $definition): array
    {
        $transactionsByHoldingId = DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->whereNotNull('stock_holding_id')
            ->whereIn('type', DepotTransaction::StockTypes)
            ->where('booked_at', '<=', $definition['cutoff'])
            ->orderBy('booked_at')
            ->orderBy('id')
            ->get(['id', 'stock_holding_id', 'type', 'pieces', 'total_amount', 'booked_at'])
            ->groupBy('stock_holding_id');
        $statesByHoldingId = $transactionsByHoldingId
            ->map(fn (Collection $transactions): array => $this->stockState($transactions, $definition['start']))
            ->filter(fn (array $state): bool => $state['opening_pieces'] > 0 || $state['period_transaction_count'] > 0);

        if ($statesByHoldingId->isEmpty()) {
            return [];
        }

        $holdingsQuery = StockHolding::query()
            ->whereKey($statesByHoldingId->keys()->all())
            ->orderBy('name')
            ->orderBy('symbol');

        if ($definition['uses_current_price']) {
            $holdingsQuery->with(['latestRealtimePrice', 'latestStockPrice']);
        }

        $holdings = $holdingsQuery->get();
        $openingPriceByHoldingId = $definition['start'] === null
            ? collect()
            : $this->historicalPriceByHoldingId($statesByHoldingId->keys(), $definition['start']->copy()->subSecond());
        $closingPriceByHoldingId = $definition['uses_current_price']
            ? collect()
            : $this->historicalPriceByHoldingId($statesByHoldingId->keys(), $definition['cutoff']);

        return $holdings
            ->map(fn (StockHolding $holding): array => $this->stockPayload(
                holding: $holding,
                state: $statesByHoldingId->get($holding->id),
                openingPrice: $openingPriceByHoldingId->get($holding->id),
                closingPrice: $definition['uses_current_price']
                    ? $this->currentPrice($holding)
                    : $closingPriceByHoldingId->get($holding->id),
            ))
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     period: string,
     *     label: string,
     *     year: ?int,
     *     start: ?Carbon,
     *     cutoff: Carbon,
     *     uses_current_price: bool
     * }
     */
    private function periodDefinition(string $period): array
    {
        $asOf = now();
        $lastYearStart = $asOf->copy()->subYear()->startOfYear();

        return match ($period) {
            'actual-year' => [
                'period' => 'actual-year',
                'label' => 'Actual Year',
                'year' => $asOf->year,
                'start' => $asOf->copy()->startOfYear(),
                'cutoff' => $asOf,
                'uses_current_price' => true,
            ],
            'last-year' => [
                'period' => 'last-year',
                'label' => 'Last Year',
                'year' => $lastYearStart->year,
                'start' => $lastYearStart,
                'cutoff' => $lastYearStart->copy()->endOfYear(),
                'uses_current_price' => false,
            ],
            '4-ever' => [
                'period' => '4-ever',
                'label' => '4-Ever',
                'year' => null,
                'start' => null,
                'cutoff' => $asOf,
                'uses_current_price' => true,
            ],
        };
    }

    /**
     * @param  Collection<int, DepotTransaction>  $transactions
     * @return array{
     *     opening_pieces: float,
     *     opening_cost: float,
     *     bought_pieces: float,
     *     bought_amount: float,
     *     sold_pieces: float,
     *     sold_amount: float,
     *     position_pieces: float,
     *     period_transaction_count: int
     * }
     */
    private function stockState(Collection $transactions, ?Carbon $periodStart): array
    {
        $openingTransactions = $periodStart === null
            ? collect()
            : $transactions->filter(
                fn (DepotTransaction $transaction): bool => $transaction->booked_at?->lt($periodStart) ?? false,
            );
        $openingLots = collect($this->openBuyLots($openingTransactions));
        $periodTransactions = $periodStart === null
            ? $transactions
            : $transactions->filter(
                fn (DepotTransaction $transaction): bool => $transaction->booked_at?->gte($periodStart) ?? false,
            );
        $buyTransactions = $periodTransactions->where('type', 'buy');
        $sellTransactions = $periodTransactions->where('type', 'sell');
        $openingPieces = (float) $openingLots->sum('pieces');
        $boughtPieces = $buyTransactions->sum(fn (DepotTransaction $transaction): float => (float) $transaction->pieces);
        $soldPieces = $sellTransactions->sum(fn (DepotTransaction $transaction): float => (float) $transaction->pieces);

        return [
            'opening_pieces' => $openingPieces,
            'opening_cost' => (float) $openingLots->sum('total_amount'),
            'bought_pieces' => $boughtPieces,
            'bought_amount' => $buyTransactions->sum(fn (DepotTransaction $transaction): float => (float) $transaction->total_amount),
            'sold_pieces' => $soldPieces,
            'sold_amount' => $sellTransactions->sum(fn (DepotTransaction $transaction): float => (float) $transaction->total_amount),
            'position_pieces' => max($openingPieces + $boughtPieces - $soldPieces, 0),
            'period_transaction_count' => $periodTransactions->count(),
        ];
    }

    /**
     * @param  Collection<int, int|string>  $holdingIds
     * @return Collection<int, string>
     */
    private function historicalPriceByHoldingId(Collection $holdingIds, Carbon $cutoff): Collection
    {
        $dailyPriceByHoldingId = StockHoldingDailyPrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->whereDate('trading_date', '<=', $cutoff->toDateString())
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('adjusted_close')
                    ->orWhereNotNull('close');
            })
            ->orderByDesc('trading_date')
            ->orderByDesc('id')
            ->get(['stock_holding_id', 'trading_date', 'adjusted_close', 'close'])
            ->groupBy('stock_holding_id')
            ->map(fn (Collection $prices): ?StockHoldingDailyPrice => $prices->first());
        $realtimePriceByHoldingId = StockRealtimePrice::query()
            ->whereIn('stock_holding_id', $holdingIds->all())
            ->whereNotNull('price')
            ->where('as_of', '<=', $cutoff)
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->get(['stock_holding_id', 'price', 'as_of'])
            ->groupBy('stock_holding_id')
            ->map(fn (Collection $prices): ?StockRealtimePrice => $prices->first());

        return $holdingIds->mapWithKeys(function (int|string $holdingId) use ($dailyPriceByHoldingId, $realtimePriceByHoldingId): array {
            $dailyPrice = $dailyPriceByHoldingId->get($holdingId);
            $realtimePrice = $realtimePriceByHoldingId->get($holdingId);
            $dailyDate = $dailyPrice?->trading_date?->copy()->startOfDay();
            $realtimeDate = $realtimePrice?->as_of?->copy()->startOfDay();
            $price = $realtimePrice?->price !== null && ($dailyDate === null || $realtimeDate?->gt($dailyDate))
                ? $realtimePrice->price
                : $dailyPrice?->adjusted_close ?? $dailyPrice?->close;

            return $price === null ? [] : [(int) $holdingId => $price];
        });
    }

    /**
     * @param  array{
     *     opening_pieces: float,
     *     opening_cost: float,
     *     bought_pieces: float,
     *     bought_amount: float,
     *     sold_pieces: float,
     *     sold_amount: float,
     *     position_pieces: float,
     *     period_transaction_count: int
     * }  $state
     * @return array<string, int|string|null>
     */
    private function stockPayload(StockHolding $holding, array $state, ?string $openingPrice, ?string $closingPrice): array
    {
        $entryPieces = $state['opening_pieces'] + $state['bought_pieces'];
        $entryValue = ($state['opening_pieces'] * $this->openingPrice($state, $openingPrice)) + $state['bought_amount'];
        $exitValue = $closingPrice === null && $state['position_pieces'] > 0
            ? null
            : $state['sold_amount'] + ($state['position_pieces'] * (float) $closingPrice);
        $averageEntryPrice = $entryPieces > 0 ? $entryValue / $entryPieces : null;
        $averageExitPrice = $entryPieces > 0 && $exitValue !== null ? $exitValue / $entryPieces : null;
        $changeAmount = $exitValue !== null ? $exitValue - $entryValue : null;
        $changePercent = $changeAmount !== null && $entryValue > 0
            ? ($changeAmount / $entryValue) * 100
            : null;

        return [
            'id' => $holding->id,
            'symbol' => $holding->symbol,
            'name' => $holding->name,
            'subtitle' => $holding->subtitle,
            'isin' => $holding->isin,
            'currency' => $holding->currency ?? 'EUR',
            'opening_pieces' => $this->decimal($state['opening_pieces'], 8),
            'bought_pieces' => $this->decimal($state['bought_pieces'], 8),
            'sold_pieces' => $this->decimal($state['sold_pieces'], 8),
            'position_pieces' => $this->decimal($state['position_pieces'], 8),
            'traded_volume' => $this->decimal($state['bought_amount'] + $state['sold_amount'], 2),
            'traded_volume_pieces' => $this->decimal($state['bought_pieces'] + $state['sold_pieces'], 8),
            'average_buy_or_year_start_price' => $averageEntryPrice === null ? null : $this->decimal($averageEntryPrice, 8),
            'average_sell_or_current_price' => $averageExitPrice === null ? null : $this->decimal($averageExitPrice, 8),
            'change_percent' => $changePercent === null ? null : $this->decimal($changePercent, 2),
            'change_amount' => $changeAmount === null ? null : $this->decimal($changeAmount, 2),
        ];
    }

    /**
     * @param  array{opening_pieces: float, opening_cost: float}  $state
     */
    private function openingPrice(array $state, ?string $historicalPrice): float
    {
        if ($state['opening_pieces'] <= 0) {
            return 0;
        }

        if (is_numeric($historicalPrice)) {
            return (float) $historicalPrice;
        }

        return $state['opening_cost'] / $state['opening_pieces'];
    }

    private function currentPrice(StockHolding $holding): ?string
    {
        return $holding->latestRealtimePrice?->price
            ?? $holding->latestStockPrice?->price
            ?? $holding->latest_price;
    }

    /**
     * @param  Collection<int, DepotTransaction>  $transactions
     * @return array<int, array{pieces: float, total_amount: float}>
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

    private function decimal(float $value, int $places): string
    {
        return number_format($value, $places, '.', '');
    }
}
