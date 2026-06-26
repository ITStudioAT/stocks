<?php

namespace App\Http\Controllers;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Services\EodhdApiUsage;
use App\Services\StockHistoricalPriceService;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;

class AdminStockHistoricalPriceController extends Controller
{
    public function ensure(StockHistoricalPriceService $historicalPriceService, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $payload = $historicalPriceService->ensure();

        return response()->json([
            ...$payload,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ], $payload['refresh'] === null ? 200 : 202);
    }

    public function status(string $refreshId, StockHistoricalPriceService $historicalPriceService, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $payload = $historicalPriceService->status($refreshId);

        if ($payload === null) {
            return response()->json([
                'message' => 'Historical stock price fetch not found.',
            ], 404);
        }

        return response()->json([
            ...$payload,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function intradayCoverage(StockHolding $holding, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
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
                'last_date' => $lastDate === null ? null : Carbon::parse($lastDate)->toDateString(),
                'row_count' => (int) $rowCount,
                'trading_day_count' => (int) $tradingDayCount,
                'table_row_count' => StockHoldingIntradayCandle::query()->count(),
            ],
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }
}
