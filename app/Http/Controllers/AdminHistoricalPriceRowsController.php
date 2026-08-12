<?php

namespace App\Http\Controllers;

use App\Http\Requests\HistoricalPriceRowsRequest;
use App\Services\EodhdApiUsage;
use App\Services\EodhdHistoricalDataService;
use Illuminate\Http\JsonResponse;

class AdminHistoricalPriceRowsController extends Controller
{
    public function show(
        HistoricalPriceRowsRequest $request,
        EodhdHistoricalDataService $historicalDataService,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $validated = $request->validated();

        return response()->json([
            'coverage' => $historicalDataService->rowCoverage((int) $validated['row_count']),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function store(
        HistoricalPriceRowsRequest $request,
        EodhdHistoricalDataService $historicalDataService,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $validated = $request->validated();
        $result = $historicalDataService->ensureRows((int) $validated['row_count']);

        return response()->json([
            ...$result,
            'message' => "EODHD historical sync: {$result['stored_count']} daily price row(s) stored.",
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }
}
