<?php

namespace App\Http\Controllers;

use App\Services\StockTradingTimeHealthCheck;
use Illuminate\Http\JsonResponse;

class AdminStockTradingTimeHealthCheckController extends Controller
{
    public function __construct(
        private StockTradingTimeHealthCheck $healthCheck,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'health_check' => $this->healthCheck->status(),
        ]);
    }

    public function store(): JsonResponse
    {
        return response()->json([
            'health_check' => $this->healthCheck->run(),
        ]);
    }
}
