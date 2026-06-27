<?php

namespace App\Http\Controllers;

use App\Jobs\ReloadEodhdExchanges;
use App\Jobs\ReloadStockHoldingIntradayData;
use App\Models\EodhdExchangeImportRun;
use App\Models\StockHolding;
use App\Models\StockHoldingIntradayReloadRun;
use App\Services\CompletedTradingDay;
use App\Services\EndOfDayDataUpdateScheduler;
use App\Services\EodhdApiUsage;
use App\Services\EodhdBatchRealtimePriceService;
use App\Services\EodhdEndOfDayDataService;
use App\Services\EodhdExchangeDataImporter;
use App\Services\IndexDataUpdateScheduler;
use App\Services\IntradayCandleBackfillScheduler;
use App\Services\PriceRefreshScheduler;
use App\Services\StockEndOfDayRepairService;
use App\Services\StockHistoricalDataRepairService;
use App\Services\StockHistoricalIntradayCandleRepairService;
use App\Services\StockHoldingIntradayDataReloader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDataController extends Controller
{
    public function exchanges(
        EodhdExchangeDataImporter $importer,
        EodhdApiUsage $eodhdApiUsage,
        IndexDataUpdateScheduler $indexDataUpdateScheduler,
    ): JsonResponse {
        $run = $importer->runningRun()
            ?? EodhdExchangeImportRun::query()->latest()->first();

        return response()->json([
            'exchanges' => $importer->exchangePayloads(),
            'refresh' => $run ? $importer->refreshPayload($run) : null,
            'index_data_update_settings' => $indexDataUpdateScheduler->payload(),
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

    public function syncRealtime(
        EodhdBatchRealtimePriceService $realtimePriceService,
        PriceRefreshScheduler $priceRefreshScheduler,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $result = $realtimePriceService->syncAll();
        $priceRefreshScheduler->markRefreshed();

        return response()->json([
            ...$result,
            'price_refresh_settings' => $priceRefreshScheduler->payload(),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function syncHistorical(
        StockHoldingIntradayDataReloader $intradayDataReloader,
        IntradayCandleBackfillScheduler $intradayBackfillScheduler,
        EodhdApiUsage $eodhdApiUsage,
        CompletedTradingDay $completedTradingDay,
    ): JsonResponse {
        $run = $intradayDataReloader->createLatestMissingRun($completedTradingDay->date());
        $intradayDataReloader->importLatestMissing($run->id);
        $run->refresh();

        return response()->json([
            'message' => "EODHD historical intraday sync: {$run->stored_count} candle(s) loaded/updated.",
            'requested_count' => $run->total_count,
            'stored_count' => $run->stored_count,
            'skipped_count' => max($run->total_count - $run->success_count - $run->failed_count, 0),
            'failed_count' => $run->failed_count,
            'date_from' => $run->date_from?->toDateString(),
            'date_to' => $run->date_to?->toDateString(),
            'refresh' => $intradayDataReloader->refreshPayload($run),
            'intraday_backfill_settings' => $intradayBackfillScheduler->markRefreshed(),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function syncEndOfDay(
        EodhdEndOfDayDataService $endOfDayDataService,
        EndOfDayDataUpdateScheduler $endOfDayDataUpdateScheduler,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $result = $endOfDayDataService->syncLatestMissing();

        return response()->json([
            ...$result,
            'message' => "EODHD end-of-day sync: {$result['stored_count']} record(s) created.",
            'end_of_day_data_update_settings' => $endOfDayDataUpdateScheduler->markRefreshed(),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function syncIndices(
        IndexDataUpdateScheduler $indexDataUpdateScheduler,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $result = $indexDataUpdateScheduler->dispatchNow();

        return response()->json([
            ...$result,
            'message' => "EODHD indices sync: {$result['refreshed_count']} index(es) refreshed.",
            'index_data_update_settings' => $indexDataUpdateScheduler->payload(),
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

    public function repair(StockEndOfDayRepairService $endOfDayRepairService, StockHistoricalDataRepairService $historicalDataRepairService): JsonResponse
    {
        return response()->json([
            'end_of_day' => $endOfDayRepairService->summary(),
            'historical_data' => $historicalDataRepairService->summary(),
        ]);
    }

    public function repairEndOfDay(StockEndOfDayRepairService $repairService, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $payload = $repairService->repair();

        return response()->json([
            ...$payload,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function repairEndOfDayStock(
        StockHolding $holding,
        StockEndOfDayRepairService $repairService,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        return response()->json([
            ...$repairService->repairHolding($holding),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function repairHistoricalData(StockHistoricalIntradayCandleRepairService $repairService, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $payload = $repairService->repair();

        return response()->json([
            ...$payload,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function repairHistoricalDataStock(
        StockHolding $holding,
        StockHistoricalIntradayCandleRepairService $repairService,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        return response()->json([
            ...$repairService->repairHolding($holding),
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
