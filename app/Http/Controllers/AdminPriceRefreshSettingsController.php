<?php

namespace App\Http\Controllers;

use App\Models\User;
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
            'trading_starts_before_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'trading_ends_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'closed_refresh_enabled' => ['required', 'boolean'],
            'closed_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        $user = $request->user();
        $updatedRefreshSchedule = $scheduler->updateSettings(
            (int) $validated['trading_interval_minutes'],
            (int) $validated['trading_starts_before_minutes'],
            (int) $validated['trading_ends_after_minutes'],
            $request->boolean('closed_refresh_enabled'),
            (int) $validated['closed_interval_minutes'],
            $user instanceof User ? $user : null,
        );

        return response()->json([
            'message' => 'Price refresh schedule updated.',
            'price_refresh_settings' => $scheduler->payload(),
            'refresh' => $updatedRefreshSchedule['refresh'],
        ]);
    }
}
