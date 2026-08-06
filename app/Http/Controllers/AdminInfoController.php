<?php

namespace App\Http\Controllers;

use App\Services\DatabaseTableInformation;
use App\Services\EodhdMethodInformation;
use Illuminate\Http\JsonResponse;

class AdminInfoController extends Controller
{
    public function show(DatabaseTableInformation $tableInformation, EodhdMethodInformation $methodInformation): JsonResponse
    {
        return response()->json([
            'tables' => $tableInformation->tables(),
            'methods' => $methodInformation->methods(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
