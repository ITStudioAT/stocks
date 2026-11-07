<?php

namespace App\Http\Controllers;

use App\Models\StockHolding;
use App\Services\FreeDailyMarketData;
use App\Services\MarketPriceStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMarketDailyHistoryController extends Controller
{
    public function __invoke(Request $request, StockHolding $holding, FreeDailyMarketData $daily): JsonResponse
    {
        abort_unless(MarketPriceStore::enabled(), 422, 'Central market data is not enabled.');
        $validated = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:3650']]);
        $result = $daily->import($holding, $validated['days'] ?? null);
        $message = match ($result['status']) {
            'cooldown' => 'Provider check is paused. The five-minute interval or a longer provider cooldown applies.',
            'unsupported' => 'No verified free daily-history mapping is available for this listing.',
            'failed' => 'Free daily-history import failed; stored prices are preserved.',
            default => 'Free daily history checked. '.$result['stored_count'].' new observations, '.$result['missing_count'].' of '.$result['expected_count'].' trading days missing.',
        };

        return response()->json([...$result, 'message' => $message, 'checked_at' => now()->toIso8601String()]);
    }
}
