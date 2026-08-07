<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Services\DepotStockPeriodSummary;
use Illuminate\Http\JsonResponse;

class AdminDepotStockPeriodController extends Controller
{
    public function __construct(private DepotStockPeriodSummary $summary) {}

    public function index(string $period): JsonResponse
    {
        $depot = Depot::query()->where('is_active', true)->first();

        return response()->json($this->summary->payload($depot, $period));
    }
}
