<?php

namespace App\Http\Controllers;

use App\Models\StockEodhdSyncRun;
use App\Services\EodhdApiUsage;
use App\Services\V2StockEodhdSyncService;
use Illuminate\Http\JsonResponse;

class AdminV2StockEodhdSyncController extends Controller
{
    public function index(V2StockEodhdSyncService $syncService, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $run = $syncService->latestRun();

        return response()->json([
            'refresh' => $run ? $syncService->payload($run) : null,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function store(V2StockEodhdSyncService $syncService, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $run = $syncService->dispatch();

        return response()->json([
            'message' => 'Stock EODHD sync queued.',
            'refresh' => $syncService->payload($run),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ], 202);
    }

    public function show(
        StockEodhdSyncRun $stockEodhdSyncRun,
        V2StockEodhdSyncService $syncService,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        return response()->json([
            'refresh' => $syncService->payload($stockEodhdSyncRun),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }
}
