<?php

namespace App\Http\Controllers;

use App\Services\UiPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUiPreferencesController extends Controller
{
    public function show(UiPreferences $uiPreferences): JsonResponse
    {
        return response()->json([
            'ui_preferences' => $uiPreferences->payload(),
        ]);
    }

    public function update(Request $request, UiPreferences $uiPreferences): JsonResponse
    {
        $validated = $request->validate([
            'depot_price_source' => ['required', 'string', Rule::in(UiPreferences::DepotPriceSources)],
        ]);

        return response()->json([
            'message' => 'UI preferences updated.',
            'ui_preferences' => $uiPreferences->updateDepotPriceSource($validated['depot_price_source']),
        ]);
    }
}
