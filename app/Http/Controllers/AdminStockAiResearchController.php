<?php

namespace App\Http\Controllers;

use App\Models\StockAiResearch;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\StockAiResearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStockAiResearchController extends Controller
{
    public function index(Request $request, StockAiResearchService $researchService): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'researches' => $researchService->latestForUser($user)
                ->map(fn (StockAiResearch $research): array => $researchService->payload($research))
                ->values()
                ->all(),
        ]);
    }

    public function store(
        Request $request,
        StockHolding $stockHolding,
        StockAiResearchService $researchService,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $research = $researchService->dispatch($user, $stockHolding);

        return response()->json([
            'message' => 'KI-Recherche wurde eingereiht.',
            'research' => $researchService->payload($research),
        ], 202);
    }

    public function show(
        Request $request,
        StockHolding $stockHolding,
        StockAiResearch $stockAiResearch,
        StockAiResearchService $researchService,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(
            (int) $stockAiResearch->user_id === (int) $user->getKey()
            && (int) $stockAiResearch->stock_holding_id === (int) $stockHolding->getKey(),
            404,
        );

        return response()->json([
            'research' => $researchService->payload($stockAiResearch),
        ]);
    }
}
