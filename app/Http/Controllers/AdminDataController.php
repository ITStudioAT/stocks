<?php

namespace App\Http\Controllers;

use App\Jobs\ReloadEodhdExchanges;
use App\Jobs\ReloadStockHoldingIntradayData;
use App\Models\EodhdExchangeImportRun;
use App\Models\StockHoldingIntradayReloadRun;
use App\Services\EodhdApiUsage;
use App\Services\EodhdExchangeDataImporter;
use App\Services\StockHoldingIntradayDataReloader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDataController extends Controller
{
    public function exchanges(EodhdExchangeDataImporter $importer, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $run = $importer->runningRun()
            ?? EodhdExchangeImportRun::query()->latest()->first();

        return response()->json([
            'exchanges' => $importer->exchangePayloads(),
            'refresh' => $run ? $importer->refreshPayload($run) : null,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function reload(EodhdExchangeDataImporter $importer, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $run = $importer->runningRun();

        if (! $run) {
            $run = $importer->createRun();

            ReloadEodhdExchanges::dispatch($run->id);
        }

        return response()->json([
            'message' => 'Exchange reload queued.',
            'exchanges' => $importer->exchangePayloads(),
            'refresh' => $importer->refreshPayload($run),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ], 202);
    }

    public function reloadStatus(string $refreshId, EodhdExchangeDataImporter $importer, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $run = EodhdExchangeImportRun::query()->find($refreshId);

        if (! $run) {
            return response()->json([
                'message' => 'Exchange reload not found.',
            ], 404);
        }

        return response()->json([
            'exchanges' => $importer->exchangePayloads(),
            'refresh' => $importer->refreshPayload($run),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function intraday(Request $request, StockHoldingIntradayDataReloader $reloader, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $holding = $reloader->selectedHolding($request->query('stock'));
        $run = $reloader->runningRun();

        return response()->json([
            'stocks' => $reloader->stockOptions(),
            'selected_stock_id' => $holding?->id,
            'days' => $holding ? $reloader->candlePayloads($holding) : [],
            'refresh' => $run ? $reloader->refreshPayload($run) : $reloader->latestRefreshPayload($holding),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function reloadIntraday(Request $request, StockHoldingIntradayDataReloader $reloader, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $validated = $request->validate([
            'selected_stock_id' => ['nullable', 'integer', 'exists:stock_holdings,id'],
            'stock_id' => ['nullable', 'integer', 'exists:stock_holdings,id'],
        ]);

        $holding = $reloader->selectedHolding($validated['selected_stock_id'] ?? $validated['stock_id'] ?? null);

        $run = $reloader->runningRun();

        if (! $run) {
            $run = $reloader->createRun();

            ReloadStockHoldingIntradayData::dispatch($run->id);
        }

        return response()->json([
            'message' => 'Intraday reload queued.',
            'stocks' => $reloader->stockOptions(),
            'selected_stock_id' => $holding?->id,
            'days' => $holding ? $reloader->candlePayloads($holding) : [],
            'refresh' => $reloader->refreshPayload($run),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ], 202);
    }

    public function reloadIntradayStatus(string $refreshId, Request $request, StockHoldingIntradayDataReloader $reloader, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $run = StockHoldingIntradayReloadRun::query()->find($refreshId);

        if (! $run) {
            return response()->json([
                'message' => 'Intraday reload not found.',
            ], 404);
        }

        $holding = $reloader->selectedHolding($request->query('stock'));

        return response()->json([
            'stocks' => $reloader->stockOptions(),
            'selected_stock_id' => $holding?->id,
            'days' => $holding ? $reloader->candlePayloads($holding) : [],
            'refresh' => $reloader->refreshPayload($run),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }
}
