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
            'analyze_trend_max_invest_amount' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'analyze_trend_virtual_buy_amount' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'analyze_trend_streak_buy_thresholds' => ['sometimes', 'array', 'min:1', 'max:20'],
            'analyze_trend_streak_buy_thresholds.*' => ['nullable', 'numeric', 'min:-100', 'max:0'],
            'analyze_trend_streak_sell_threshold' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        if (
            ! array_key_exists('depot_price_source', $validated)
            && ! array_key_exists('analyze_trend_row_limit', $validated)
            && ! array_key_exists('analyze_trend_max_invest_amount', $validated)
            && ! array_key_exists('analyze_trend_virtual_buy_amount', $validated)
            && ! array_key_exists('analyze_trend_streak_buy_thresholds', $validated)
            && ! array_key_exists('analyze_trend_streak_sell_threshold', $validated)
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
            array_key_exists('analyze_trend_max_invest_amount', $validated)
            || array_key_exists('analyze_trend_virtual_buy_amount', $validated)
        ) {
            return response()->json([
                'message' => 'UI preferences updated.',
                'ui_preferences' => $uiPreferences->updateAnalyzeTrendInvestmentSettings(
                    $request->user(),
                    $validated['analyze_trend_max_invest_amount'] ?? null,
                    $validated['analyze_trend_virtual_buy_amount'] ?? null,
                ),
            ]);
        }

        if (
            array_key_exists('analyze_trend_streak_buy_thresholds', $validated)
            || array_key_exists('analyze_trend_streak_sell_threshold', $validated)
        ) {
            return response()->json([
                'message' => 'UI preferences updated.',
                'ui_preferences' => $uiPreferences->updateAnalyzeTrendStreakSettings(
                    $request->user(),
                    $validated['analyze_trend_streak_buy_thresholds'] ?? null,
                    $validated['analyze_trend_streak_sell_threshold'] ?? null,
                ),
            ]);
        }

        throw ValidationException::withMessages([
            'ui_preferences' => 'Provide a UI preference to update.',
        ]);
    }
}
