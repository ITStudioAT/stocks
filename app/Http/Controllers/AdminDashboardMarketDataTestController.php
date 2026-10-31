<?php

namespace App\Http\Controllers;

use App\Models\StockHolding;
use App\Services\DeutscheBoerseDelayedDataTest;
use App\Services\StoredQuoteComparison;
use App\Services\UsMarketDataTest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdminDashboardMarketDataTestController extends Controller
{
    public function store(Request $request, DeutscheBoerseDelayedDataTest $test, StoredQuoteComparison $comparison, UsMarketDataTest $usTest): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['prohibited'],
            'round' => ['prohibited'],
            'batch' => ['sometimes', 'integer', 'min:0', 'max:9'],
            'remaining_isins' => ['sometimes', 'array', 'max:50'],
            'remaining_isins.*' => ['required', 'string', 'size:12', 'distinct'],
            'before' => ['nullable', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
        ]);
        $holdings = StockHolding::query()->whereNotNull('isin')->orderBy('name')->limit(51)
            ->get(['id', 'isin', 'symbol', 'name', 'exchange', 'mic_code', 'currency']);
        try {
            $selectedHoldings = $holdings->take(50);
            if (isset($validated['remaining_isins'])) {
                $selectedHoldings = $selectedHoldings->whereIn('isin', $validated['remaining_isins']);
            }
            $instruments = $selectedHoldings->map(fn (StockHolding $holding): array => $holding->only(['isin', 'symbol', 'name', 'exchange', 'mic_code', 'currency']));
            $batch = (int) ($validated['batch'] ?? 0);
            $result = $batch === 9 ? $usTest->run($instruments)
                : $test->runBatch($instruments, $batch, $validated['before'] ?? null);
            $result = $comparison->attach($result, $selectedHoldings);

            return response()->json(['test' => [...$result, 'instrument_limit_reached' => $holdings->count() > 50]])->header('Cache-Control', 'no-store');
        } catch (RuntimeException) {
            return response()->json(['message' => 'Der Test konnte nicht abgeschlossen werden. Quelle derzeit nicht erreichbar, unerwartetes Format oder Zugriff in dieser Umgebung gesperrt.'], 503)
                ->header('Cache-Control', 'no-store');
        }
    }
}
