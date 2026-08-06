<?php

namespace App\Http\Controllers;

use App\Services\V2IndexEodhdSyncScheduler;
use App\Services\V2IndexRealtimeScheduler;
use Illuminate\Http\JsonResponse;

class AdminV2IndexRealtimeSyncController extends Controller
{
    public function store(
        V2IndexRealtimeScheduler $realtimeScheduler,
        V2IndexEodhdSyncScheduler $eodhdSyncScheduler,
    ): JsonResponse {
        $queued = $realtimeScheduler->dispatchDue() === 1;

        return response()->json([
            'message' => $queued
                ? 'Overdue index realtime update queued.'
                : 'No overdue index realtime update was queued.',
            'queued' => $queued,
            'index_eodhd_sync_settings' => $eodhdSyncScheduler->payload(),
        ], $queued ? 202 : 200);
    }
}
