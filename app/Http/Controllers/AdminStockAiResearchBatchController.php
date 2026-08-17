<?php

namespace App\Http\Controllers;

use App\Models\StockAiResearch;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\StockAiResearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStockAiResearchBatchController extends Controller
{
    public function store(Request $request, StockAiResearchService $researchService): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $researches = StockHolding::query()
            ->oldest('id')
            ->get(['id'])
            ->map(fn (StockHolding $stockHolding): StockAiResearch => $researchService->dispatch($user, $stockHolding));

        return response()->json([
            'message' => "{$researches->count()} KI-Recherchen wurden eingereiht.",
            'count' => $researches->count(),
            'researches' => $researches->map(fn (StockAiResearch $research): array => [
                'id' => $research->id,
                'stock_holding_id' => $research->stock_holding_id,
                'status' => $research->status,
                'message' => $research->message,
            ])->values()->all(),
        ], 202);
    }
}
