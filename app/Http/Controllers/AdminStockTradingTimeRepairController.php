<?php

namespace App\Http\Controllers;

use App\Services\StockTradingTimeHealthCheck;
use Illuminate\Http\JsonResponse;

class AdminStockTradingTimeRepairController extends Controller
{
    public function __construct(
        private StockTradingTimeHealthCheck $healthCheck,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'health_check' => $this->healthCheck->repair(),
        ]);
    }
}
