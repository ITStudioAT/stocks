<?php

namespace App\Http\Controllers;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Services\CompletedTradingDay;
use App\Services\EodhdApiUsage;
use App\Services\EodhdHistoricalDataService;
use App\Services\IndexDataUpdateScheduler;
use App\Services\StockHistoricalPriceService;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AdminStockHistoricalPriceController extends Controller
{
    public function coverage(
        StockHistoricalPriceService $historicalPriceService,
        EodhdApiUsage $eodhdApiUsage,
        IndexDataUpdateScheduler $indexDataUpdateScheduler,
    ): JsonResponse {
        return response()->json([
            'coverage' => $historicalPriceService->coverage(),
            'refresh' => null,
            'index_data_update_settings' => $indexDataUpdateScheduler->payload(),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function ensure(
        StockHistoricalPriceService $historicalPriceService,
        EodhdHistoricalDataService $historicalDataService,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $coverage = $historicalPriceService->coverage();
        $result = $historicalDataService->syncAll(Carbon::parse($coverage['required_to'], 'Europe/Vienna'));
        $coverage = $historicalPriceService->coverage();

        return response()->json([
            ...$result,
            'message' => "EODHD historical sync: {$result['stored_count']} record(s) created.",
            'coverage' => $coverage,
            'refresh' => null,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function intradayCoverage(
        StockHolding $holding,
        CompletedTradingDay $completedTradingDay,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $candles = StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->where('interval', '5m')
            ->where('source_key', 'eodhd_intraday');
        $firstDate = (clone $candles)->min('trading_date');
        $lastDate = (clone $candles)->max('trading_date');
        $rowCount = (clone $candles)->count();
        $tradingDayCount = (clone $candles)->distinct('trading_date')->count('trading_date');

        return response()->json([
            'holding_id' => $holding->id,
            'coverage' => [
                'first_date' => $firstDate === null ? null : Carbon::parse($firstDate)->toDateString(),
                'expected_last_date' => $completedTradingDay->date()->toDateString(),
                'last_date' => $lastDate === null ? null : Carbon::parse($lastDate)->toDateString(),
                'oldest_last_date' => $this->oldestIntradayLastDate(),
                'outdated_stocks' => $this->outdatedIntradayStocks($completedTradingDay->date()->toDateString()),
                'row_count' => (int) $rowCount,
                'trading_day_count' => (int) $tradingDayCount,
                'table_row_count' => StockHoldingIntradayCandle::query()->count(),
            ],
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    private function oldestIntradayLastDate(): ?string
    {
        $latestDates = StockHolding::query()
            ->leftJoin('stock_holding_intraday_candles', function (JoinClause $join): void {
                $join->on('stock_holdings.id', '=', 'stock_holding_intraday_candles.stock_holding_id')
                    ->where('stock_holding_intraday_candles.interval', '5m')
                    ->where('stock_holding_intraday_candles.source_key', 'eodhd_intraday');
            })
            ->groupBy('stock_holdings.id')
            ->selectRaw('MAX(stock_holding_intraday_candles.trading_date) as latest_date')
            ->pluck('latest_date');

        if ($latestDates->contains(null)) {
            return null;
        }

        $oldestLastDate = $latestDates->min();

        return $oldestLastDate === null ? null : Carbon::parse($oldestLastDate)->toDateString();
    }

    /**
     * @return array<int, array{id: int, label: string, db_last_date: ?string}>
     */
    private function outdatedIntradayStocks(string $expectedLastDate): array
    {
        return StockHolding::query()
            ->leftJoin('stock_holding_intraday_candles', function (JoinClause $join): void {
                $join->on('stock_holdings.id', '=', 'stock_holding_intraday_candles.stock_holding_id')
                    ->where('stock_holding_intraday_candles.interval', '5m')
                    ->where('stock_holding_intraday_candles.source_key', 'eodhd_intraday');
            })
            ->groupBy('stock_holdings.id', 'stock_holdings.symbol', 'stock_holdings.name', 'stock_holdings.subtitle')
            ->orderBy('stock_holdings.name')
            ->orderBy('stock_holdings.symbol')
            ->select([
                'stock_holdings.id',
                'stock_holdings.symbol',
                'stock_holdings.name',
                'stock_holdings.subtitle',
            ])
            ->selectRaw('MAX(stock_holding_intraday_candles.trading_date) as latest_date')
            ->get()
            ->map(function (StockHolding $holding) use ($expectedLastDate): ?array {
                $lastDate = $holding->latest_date === null
                    ? null
                    : Carbon::parse($holding->latest_date)->toDateString();

                if ($lastDate === $expectedLastDate) {
                    return null;
                }

                return [
                    'id' => $holding->id,
                    'label' => collect([$holding->symbol, $holding->name, $holding->subtitle])->filter()->implode(' - '),
                    'db_last_date' => $lastDate,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
