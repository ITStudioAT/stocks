<?php

namespace App\Http\Controllers;

use App\Services\EodhdApiUsage;
use App\Services\StockHistoricalPriceService;
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
}
