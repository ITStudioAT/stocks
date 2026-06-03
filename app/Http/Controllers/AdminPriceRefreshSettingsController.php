<?php

namespace App\Http\Controllers;

use App\Services\PriceRefreshScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPriceRefreshSettingsController extends Controller
{
    public function show(PriceRefreshScheduler $scheduler): JsonResponse
    {
        return response()->json([
            'price_refresh_settings' => $scheduler->payload(),
        ]);
    }

    public function update(Request $request, PriceRefreshScheduler $scheduler): JsonResponse
    {
        $validated = $request->validate([
            'trading_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'closed_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        $scheduler->updateIntervals(
            (int) $validated['trading_interval_minutes'],
            (int) $validated['closed_interval_minutes'],
        );

        return response()->json([
            'message' => 'Price refresh schedule updated.',
            'price_refresh_settings' => $scheduler->payload(),
        ]);
    }
}
