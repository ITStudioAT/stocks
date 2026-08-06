<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateV2IndexEodhdSyncScheduleRequest;
use App\Services\V2IndexEodhdSyncScheduler;
use App\Services\V2IndexRealtimeScheduler;
use Illuminate\Http\JsonResponse;

class AdminV2IndexEodhdSyncSettingsController extends Controller
{
    public function show(V2IndexEodhdSyncScheduler $scheduler): JsonResponse
    {
        return response()->json([
            'index_eodhd_sync_settings' => $scheduler->payload(),
        ]);
    }

    public function update(
        UpdateV2IndexEodhdSyncScheduleRequest $request,
        V2IndexEodhdSyncScheduler $scheduler,
        V2IndexRealtimeScheduler $realtimeScheduler,
    ): JsonResponse {
        $validated = $request->validated();

        if (isset($validated['times'])) {
            $scheduler->updateSettings($validated['times']);
        }

        if (isset($validated['realtime'])) {
            $realtime = $validated['realtime'];
            $realtimeScheduler->updateSettings(
                $realtime['trading_interval_minutes'],
                $realtime['trading_starts_before_minutes'],
                $realtime['trading_ends_after_minutes'],
                $realtime['closed_refresh_enabled'],
                $realtime['closed_interval_minutes'],
            );
        }

        return response()->json([
            'message' => isset($validated['realtime'])
                ? 'Automatic index realtime schedule saved.'
                : 'Automatic index update times saved.',
            'index_eodhd_sync_settings' => $scheduler->payload(),
        ]);
    }
}
