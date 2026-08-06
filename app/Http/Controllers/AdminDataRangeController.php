<?php

namespace App\Http\Controllers;

use App\MarketDataType;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Services\StoredMarketDataRange;
use Illuminate\Http\JsonResponse;

class AdminDataRangeController extends Controller
{
    public function index(
        IndexWatchItem $indexWatchItem,
        MarketDataType $dataType,
        StoredMarketDataRange $storedMarketDataRange,
    ): JsonResponse {
        return response()->json([
            'data_type' => $dataType->value,
            'range' => $storedMarketDataRange->forIndex($indexWatchItem, $dataType),
        ]);
    }

    public function stock(
        StockHolding $holding,
        MarketDataType $dataType,
        StoredMarketDataRange $storedMarketDataRange,
    ): JsonResponse {
        return response()->json([
            'data_type' => $dataType->value,
            'range' => $storedMarketDataRange->forStock($holding, $dataType),
        ]);
    }
}
