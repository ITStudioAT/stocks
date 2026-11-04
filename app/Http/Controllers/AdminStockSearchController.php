<?php

namespace App\Http\Controllers;

use App\Services\FreeInstrumentSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStockSearchController extends Controller
{
    public function index(Request $request, FreeInstrumentSearch $freeSearch): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'source' => ['sometimes', 'required', 'in:free,eodhd'],
        ]);

        if (($validated['source'] ?? 'free') !== 'free') {
            return response()->json(['message' => 'EODHD search is disabled. Only free, saved or verified instruments are available.'], 422);
        }

        return response()->json($freeSearch->search($validated['query']));
    }
}
