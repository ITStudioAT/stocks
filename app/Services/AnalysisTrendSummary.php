<?php

namespace App\Services;

use App\Models\Depot;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use Illuminate\Database\Eloquent\Builder;

class AnalysisTrendSummary
{
    public function __construct(private DepotTransactionBooker $depotTransactionBooker) {}

    /** @return array<int, array{id: int, name: ?string, symbol: ?string, position_pieces: string, direction: ?string, count: ?int, trading_date: ?string}> */
    public function all(): array
    {
        $holdings = StockHolding::query()->get(['id', 'name', 'symbol'])->keyBy('id');
        $activeDepot = Depot::query()->where('is_active', true)->first();
        $positions = $activeDepot ? $this->depotTransactionBooker->positionPiecesByHolding($activeDepot) : collect();
        $summaries = $holdings->map(fn (StockHolding $holding): array => [
            'id' => $holding->id,
            'name' => $holding->name,
            'symbol' => $holding->symbol,
            'position_pieces' => $positions->get($holding->id, '0.00000000'),
            'direction' => null,
            'count' => null,
            'trading_date' => null,
        ])->all();
        $holdingId = null;
        $previous = null;
        $latestDate = null;
        $direction = 0;
        $count = 0;
        $pendingFlatCount = 0;
        $finished = false;

        $prices = StockHoldingDailyPrice::query()
            ->select(['id', 'stock_holding_id', 'trading_date', 'close', 'adjusted_close', 'currency'])
            ->where(function (Builder $query): void {
                $query->whereNotNull('adjusted_close')->orWhereNotNull('close');
            })
            ->orderBy('stock_holding_id')
            ->orderByDesc('trading_date')
            ->orderByDesc('id')
            ->lazy();

        foreach ($prices as $price) {
            if ((int) $price->stock_holding_id !== $holdingId) {
                $this->applySummary($summaries, $holdingId, $latestDate, $direction, $count);

                $holdingId = (int) $price->stock_holding_id;
                $previous = null;
                $latestDate = $price->trading_date->toDateString();
                $direction = 0;
                $count = 0;
                $pendingFlatCount = 0;
                $finished = false;
            }

            if ($finished) {
                continue;
            }

            $current = [
                'trading_date' => $price->trading_date->toDateString(),
                'price' => (string) ($price->adjusted_close ?? $price->close),
                'currency' => $price->currency,
            ];

            if ($previous === null) {
                $previous = $current;

                continue;
            }

            if ($previous['trading_date'] === $current['trading_date']) {
                continue;
            }

            $olderDirection = $this->direction($previous, $current);
            $previous = $current;

            if ($olderDirection === null || ($direction !== 0 && $olderDirection !== 0 && $olderDirection !== $direction)) {
                $finished = true;

                continue;
            }

            if ($olderDirection === 0) {
                $pendingFlatCount++;

                continue;
            }

            $direction = $olderDirection;
            $count += $pendingFlatCount + 1;
            $pendingFlatCount = 0;
        }

        $this->applySummary($summaries, $holdingId, $latestDate, $direction, $count);
        $summaries = array_values($summaries);

        usort($summaries, fn (array $first, array $second): int => (($second['count'] ?? -1) <=> ($first['count'] ?? -1))
            ?: strcasecmp($first['name'] ?: $first['symbol'] ?: '', $second['name'] ?: $second['symbol'] ?: '')
            ?: ($first['id'] <=> $second['id']));

        return $summaries;
    }

    /** @param array<int, array{id: int, name: ?string, symbol: ?string, position_pieces: string, direction: ?string, count: ?int, trading_date: ?string}> $summaries */
    private function applySummary(array &$summaries, ?int $holdingId, ?string $latestDate, int $direction, int $count): void
    {
        if ($holdingId === null || ! isset($summaries[$holdingId])) {
            return;
        }

        $summaries[$holdingId]['trading_date'] = $latestDate;
        $summaries[$holdingId]['direction'] = $direction === 0 ? null : ($direction > 0 ? 'up' : 'down');
        $summaries[$holdingId]['count'] = $direction === 0 ? null : $count;
    }

    /**
     * @param  array{price: string, currency: ?string}  $newer
     * @param  array{price: string, currency: ?string}  $older
     */
    public function direction(array $newer, array $older): ?int
    {
        if ($newer['currency'] === null || $newer['currency'] === '' || $newer['currency'] !== $older['currency']) {
            return null;
        }

        return (float) $newer['price'] <=> (float) $older['price'];
    }
}
