<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use Illuminate\Support\Carbon;

class StockHistoricalDataRepairService
{
    /**
     * @return array{
     *     minimum_date: string,
     *     actual_minimum_date: ?string,
     *     last_trading_day: string,
     *     actual_last_trading_day: ?string,
     *     total_stocks_count: int,
     *     covered_stocks_count: int,
     *     missing_stocks_count: int,
     *     missing_stocks: array<int, array{id: int, label: string}>,
     * }
     */
    public function summary(): array
    {
        $minimumDate = now()->subYear()->toDateString();
        $lastTradingDay = $this->lastTradingDay()->toDateString();
        $totalStocksCount = StockHolding::query()->count();
        $coveredStocksCount = $this->coveredStocksCount($minimumDate, $lastTradingDay);
        $missingStocks = $this->missingStocks($minimumDate, $lastTradingDay);

        return [
            'minimum_date' => $minimumDate,
            'actual_minimum_date' => $this->actualMinimumDate(),
            'last_trading_day' => $lastTradingDay,
            'actual_last_trading_day' => $this->actualLastTradingDay(),
            'total_stocks_count' => $totalStocksCount,
            'covered_stocks_count' => $coveredStocksCount,
            'missing_stocks_count' => $totalStocksCount - $coveredStocksCount,
            'missing_stocks' => $missingStocks,
        ];
    }

    private function coveredStocksCount(string $minimumDate, string $lastTradingDay): int
    {
        return StockHoldingIntradayCandle::query()
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->selectRaw('stock_holding_id, MIN(trading_date) as first_date, MAX(trading_date) as last_date')
            ->groupBy('stock_holding_id')
            ->havingRaw('MIN(trading_date) <= ?', [$minimumDate])
            ->havingRaw('MAX(trading_date) >= ?', [$lastTradingDay])
            ->count();
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    private function missingStocks(string $minimumDate, string $lastTradingDay): array
    {
        $coveredHoldingIds = StockHoldingIntradayCandle::query()
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->selectRaw('stock_holding_id, MIN(trading_date) as first_date, MAX(trading_date) as last_date')
            ->groupBy('stock_holding_id')
            ->havingRaw('MIN(trading_date) <= ?', [$minimumDate])
            ->havingRaw('MAX(trading_date) >= ?', [$lastTradingDay])
            ->pluck('stock_holding_id')
            ->flip();

        return StockHolding::query()
            ->orderBy('name')
            ->orderBy('symbol')
            ->get(['id', 'name', 'symbol'])
            ->filter(fn (StockHolding $holding): bool => ! $coveredHoldingIds->has($holding->id))
            ->map(fn (StockHolding $holding): array => [
                'id' => $holding->id,
                'label' => collect([$holding->symbol, $holding->name])->filter()->implode(' - '),
            ])
            ->values()
            ->all();
    }

    private function actualMinimumDate(): ?string
    {
        $actualMinimumDate = StockHoldingIntradayCandle::query()
            ->min('trading_date');

        return $actualMinimumDate === null
            ? null
            : Carbon::parse($actualMinimumDate)->toDateString();
    }

    private function actualLastTradingDay(): ?string
    {
        $actualLastTradingDay = StockHoldingIntradayCandle::query()
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->selectRaw('stock_holding_id, MAX(trading_date) as latest_trading_date')
            ->groupBy('stock_holding_id')
            ->pluck('latest_trading_date')
            ->min();

        return $actualLastTradingDay === null
            ? null
            : Carbon::parse($actualLastTradingDay)->toDateString();
    }

    private function lastTradingDay(): Carbon
    {
        $date = now()->startOfDay()->subDay();

        while ($date->isWeekend()) {
            $date->subDay();
        }

        return $date;
    }
}
