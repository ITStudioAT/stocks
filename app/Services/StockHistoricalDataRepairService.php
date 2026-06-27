<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use Illuminate\Support\Carbon;

class StockHistoricalDataRepairService
{
    public function __construct(
        private CompletedTradingDay $completedTradingDay,
    ) {}

    /**
     * @return array{
     *     minimum_date: string,
     *     actual_minimum_date: ?string,
     *     last_trading_day: string,
     *     actual_last_trading_day: ?string,
     *     total_stocks_count: int,
     *     covered_stocks_count: int,
     *     missing_stocks_count: int,
     *     missing_stocks: array<int, array{id: int, label: string, db_minimum_date: ?string, db_last_trading_day: ?string, missing_ranges: array<int, array{from: string, to: string}>}>,
     * }
     */
    public function summary(): array
    {
        $minimumDate = now()->subYear()->toDateString();
        $lastTradingDay = $this->completedTradingDay->date()->toDateString();
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
            ->havingRaw('DATE(MIN(trading_date)) <= ?', [$minimumDate])
            ->havingRaw('DATE(MAX(trading_date)) >= ?', [$lastTradingDay])
            ->pluck('stock_holding_id')
            ->count();
    }

    /**
     * @return array<int, array{id: int, label: string, db_minimum_date: ?string, db_last_trading_day: ?string, missing_ranges: array<int, array{from: string, to: string}>}>
     */
    private function missingStocks(string $minimumDate, string $lastTradingDay): array
    {
        $coverageByHoldingId = StockHoldingIntradayCandle::query()
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday')
            ->selectRaw('stock_holding_id, MIN(trading_date) as first_date, MAX(trading_date) as last_date')
            ->groupBy('stock_holding_id')
            ->get()
            ->keyBy('stock_holding_id');

        return StockHolding::query()
            ->orderBy('name')
            ->orderBy('symbol')
            ->get(['id', 'name', 'symbol'])
            ->filter(fn (StockHolding $holding): bool => ! $this->isCovered($coverageByHoldingId->get($holding->id), $minimumDate, $lastTradingDay))
            ->map(function (StockHolding $holding) use ($coverageByHoldingId, $minimumDate, $lastTradingDay): array {
                $coverage = $coverageByHoldingId->get($holding->id);

                return [
                    'id' => $holding->id,
                    'label' => collect([$holding->symbol, $holding->name])->filter()->implode(' - '),
                    'db_minimum_date' => $this->coverageDate($coverage?->first_date),
                    'db_last_trading_day' => $this->coverageDate($coverage?->last_date),
                    'missing_ranges' => $this->missingRanges($coverage, $minimumDate, $lastTradingDay),
                ];
            })
            ->unique('id')
            ->values()
            ->all();
    }

    private function isCovered(mixed $coverage, string $minimumDate, string $lastTradingDay): bool
    {
        $firstDate = $this->coverageDate($coverage?->first_date);
        $lastDate = $this->coverageDate($coverage?->last_date);

        return $firstDate !== null
            && $lastDate !== null
            && $firstDate <= $minimumDate
            && $lastDate >= $lastTradingDay;
    }

    /**
     * @return array<int, array{from: string, to: string}>
     */
    private function missingRanges(mixed $coverage, string $minimumDate, string $lastTradingDay): array
    {
        $firstDate = $this->coverageDate($coverage?->first_date);
        $lastDate = $this->coverageDate($coverage?->last_date);

        if ($firstDate === null || $lastDate === null) {
            return [
                [
                    'from' => $minimumDate,
                    'to' => $lastTradingDay,
                ],
            ];
        }

        $ranges = [];

        if ($firstDate > $minimumDate) {
            $ranges[] = [
                'from' => $minimumDate,
                'to' => Carbon::parse($firstDate)->subDay()->toDateString(),
            ];
        }

        if ($lastDate < $lastTradingDay) {
            $ranges[] = [
                'from' => Carbon::parse($lastDate)->addDay()->toDateString(),
                'to' => $lastTradingDay,
            ];
        }

        return $ranges;
    }

    private function coverageDate(mixed $date): ?string
    {
        return $date === null
            ? null
            : Carbon::parse($date)->toDateString();
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
}
