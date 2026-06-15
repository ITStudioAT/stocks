<?php

namespace App\Http\Controllers;

use App\Services\UiPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUiPreferencesController extends Controller
{
    public function show(Request $request, UiPreferences $uiPreferences): JsonResponse
    {
        return response()->json([
            'ui_preferences' => $uiPreferences->payload($request->user()),
        ]);
    }

    public function update(Request $request, UiPreferences $uiPreferences): JsonResponse
    {
        $validated = $request->validate([
            'depot_price_source' => ['sometimes', 'string', Rule::in(UiPreferences::DepotPriceSources)],
            'analyze_trend_row_limit' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'analyze_trend_excluded_holding_ids' => ['sometimes', 'array'],
            'analyze_trend_excluded_holding_ids.*' => ['integer', 'min:1'],
            'analyze_trend_trade_amounts' => ['sometimes', 'array', 'size:3'],
            'analyze_trend_trade_amounts.*' => ['integer', 'min:0', 'max:1000000'],
            'analyze_trend_max_invest_amount' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);

        if (
            ! array_key_exists('depot_price_source', $validated)
            && ! array_key_exists('analyze_trend_row_limit', $validated)
            && ! array_key_exists('analyze_trend_excluded_holding_ids', $validated)
            && ! array_key_exists('analyze_trend_trade_amounts', $validated)
            && ! array_key_exists('analyze_trend_max_invest_amount', $validated)
        ) {
            throw ValidationException::withMessages([
                'ui_preferences' => 'Provide a UI preference to update.',
            ]);
        }

        if (array_key_exists('depot_price_source', $validated)) {
            return response()->json([
                'message' => 'UI preferences updated.',
                'ui_preferences' => $uiPreferences->updateDepotPriceSource($validated['depot_price_source'], $request->user()),
            ]);
        }

        if (array_key_exists('analyze_trend_row_limit', $validated)) {
            return response()->json([
                'message' => 'UI preferences updated.',
                'ui_preferences' => $uiPreferences->updateAnalyzeTrendRowLimit(
                    $request->user(),
                    $validated['analyze_trend_row_limit'],
                ),
            ]);
        }

        if (
            array_key_exists('analyze_trend_trade_amounts', $validated)
            || array_key_exists('analyze_trend_max_invest_amount', $validated)
        ) {
            return response()->json([
                'message' => 'UI preferences updated.',
                'ui_preferences' => $uiPreferences->updateAnalyzeTrendInvestmentSettings(
                    $request->user(),
                    $validated['analyze_trend_trade_amounts'] ?? null,
                    $validated['analyze_trend_max_invest_amount'] ?? null,
                ),
            ]);
        }

        return response()->json([
            'message' => 'UI preferences updated.',
            'ui_preferences' => $uiPreferences->updateAnalyzeTrendExcludedHoldingIds(
                $request->user(),
                $validated['analyze_trend_excluded_holding_ids'],
            ),
        ]);
    }
}
