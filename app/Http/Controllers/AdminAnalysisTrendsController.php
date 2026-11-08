<?php

namespace App\Http\Controllers;

use App\Services\AnalysisTrendSummary;
use Illuminate\Http\JsonResponse;

class AdminAnalysisTrendsController extends Controller
{
    public function __invoke(AnalysisTrendSummary $trends): JsonResponse
    {
        return response()->json(['trends' => $trends->all()]);
    }
}
