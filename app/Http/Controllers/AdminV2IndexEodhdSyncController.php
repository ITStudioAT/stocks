<?php

namespace App\Http\Controllers;

use App\Models\IndexEodhdSyncRun;
use App\Services\EodhdApiUsage;
use App\Services\V2IndexEodhdSyncScheduler;
use App\Services\V2IndexEodhdSyncService;
use Illuminate\Http\JsonResponse;

class AdminV2IndexEodhdSyncController extends Controller
{
    public function store(
        V2IndexEodhdSyncService $syncService,
        V2IndexEodhdSyncScheduler $scheduler,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $run = $scheduler->dispatchNow();

        return response()->json([
            'message' => 'Index EODHD sync queued.',
            'refresh' => $syncService->payload($run),
            'index_eodhd_sync_settings' => $scheduler->payload(),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ], 202);
    }

    public function show(
        IndexEodhdSyncRun $indexEodhdSyncRun,
        V2IndexEodhdSyncService $syncService,
        V2IndexEodhdSyncScheduler $scheduler,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        return response()->json([
            'refresh' => $syncService->payload($indexEodhdSyncRun),
            'index_eodhd_sync_settings' => $scheduler->payload(),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }
}
