<?php

namespace App\Http\Controllers;

use App\Models\StockHoldingIntradayReloadRun;
use App\Models\User;
use App\Services\EndOfDayDataUpdateScheduler;
use App\Services\EodhdApiUsage;
use App\Services\IndexDataUpdateScheduler;
use App\Services\IndexPriceRefreshSettings;
use App\Services\IntradayCandleBackfillScheduler;
use App\Services\PriceRefreshScheduler;
use App\Services\StockHoldingIntradayDataReloader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPriceRefreshSettingsController extends Controller
{
    public function show(
        PriceRefreshScheduler $scheduler,
        IndexPriceRefreshSettings $indexPriceRefreshSettings,
        IntradayCandleBackfillScheduler $intradayBackfillScheduler,
        EndOfDayDataUpdateScheduler $endOfDayDataUpdateScheduler,
        IndexDataUpdateScheduler $indexDataUpdateScheduler,
        StockHoldingIntradayDataReloader $intradayDataReloader,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $intradayBackfillRun = $intradayDataReloader->runningRun()
            ?? StockHoldingIntradayReloadRun::query()
                ->whereNull('stock_holding_id')
                ->where('id', 'like', 'intraday-missing-%')
                ->latest('finished_at')
                ->latest()
                ->first();

        return response()->json([
            'price_refresh_settings' => $scheduler->payload(),
            'index_price_refresh_settings' => $indexPriceRefreshSettings->payload(),
            'intraday_backfill_settings' => $intradayBackfillScheduler->payload(),
            'end_of_day_data_update_settings' => $endOfDayDataUpdateScheduler->payload(),
            'index_data_update_settings' => $indexDataUpdateScheduler->payload(),
            'refresh' => $scheduler->activeRefreshProgress(),
            'intraday_backfill_refresh' => $intradayBackfillRun ? $intradayDataReloader->refreshPayload($intradayBackfillRun) : null,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function update(Request $request, PriceRefreshScheduler $scheduler, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $validated = $request->validate([
            'trading_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'trading_starts_before_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'trading_ends_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'trading_start_time' => ['nullable', 'date_format:H:i'],
            'trading_end_time' => ['nullable', 'date_format:H:i'],
            'closed_refresh_enabled' => ['required', 'boolean'],
            'closed_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        $user = $request->user();
        $updatedRefreshSchedule = $scheduler->updateSettings(
            (int) $validated['trading_interval_minutes'],
            (int) $validated['trading_starts_before_minutes'],
            (int) $validated['trading_ends_after_minutes'],
            $request->boolean('closed_refresh_enabled'),
            (int) $validated['closed_interval_minutes'],
            $user instanceof User ? $user : null,
            $validated['trading_start_time'] ?? null,
            $validated['trading_end_time'] ?? null,
        );

        return response()->json([
            'message' => 'Price refresh schedule updated.',
            'price_refresh_settings' => $scheduler->payload(),
            'refresh' => $updatedRefreshSchedule['refresh'] ?? $scheduler->activeRefreshProgress(),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function updateIndex(Request $request, IndexPriceRefreshSettings $indexPriceRefreshSettings, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        $validated = $request->validate([
            'trading_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'trading_starts_before_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'trading_ends_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'closed_refresh_enabled' => ['required', 'boolean'],
            'closed_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        $indexPriceRefreshSettings->updateSettings(
            (int) $validated['trading_interval_minutes'],
            (int) $validated['trading_starts_before_minutes'],
            (int) $validated['trading_ends_after_minutes'],
            $request->boolean('closed_refresh_enabled'),
            (int) $validated['closed_interval_minutes'],
        );

        return response()->json([
            'message' => 'Index price refresh schedule updated.',
            'index_price_refresh_settings' => $indexPriceRefreshSettings->payload(),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function updateIntradayBackfill(
        Request $request,
        IntradayCandleBackfillScheduler $intradayBackfillScheduler,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $validated = $request->validate([
            'daily_time' => ['required', 'date_format:H:i'],
            'interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        return response()->json([
            'message' => 'Intraday backfill schedule updated.',
            'intraday_backfill_settings' => $intradayBackfillScheduler->updateSettings(
                (string) $validated['daily_time'],
                isset($validated['interval_minutes']) ? (int) $validated['interval_minutes'] : null,
            ),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function updateEndOfDayData(
        Request $request,
        EndOfDayDataUpdateScheduler $endOfDayDataUpdateScheduler,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $validated = $request->validate([
            'daily_time' => ['required', 'date_format:H:i'],
            'interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        return response()->json([
            'message' => 'End-of-day data update schedule updated.',
            'end_of_day_data_update_settings' => $endOfDayDataUpdateScheduler->updateSettings(
                (string) $validated['daily_time'],
                isset($validated['interval_minutes']) ? (int) $validated['interval_minutes'] : null,
            ),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function updateIndexData(
        Request $request,
        IndexDataUpdateScheduler $indexDataUpdateScheduler,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $validated = $request->validate([
            'weekday' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        return response()->json([
            'message' => 'Indices data update schedule updated.',
            'index_data_update_settings' => $indexDataUpdateScheduler->updateSettings((int) $validated['weekday']),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function runIntradayBackfill(
        IntradayCandleBackfillScheduler $intradayBackfillScheduler,
        StockHoldingIntradayDataReloader $intradayDataReloader,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $run = $intradayBackfillScheduler->dispatchNow();

        return response()->json([
            'message' => 'Missing intraday backfill queued.',
            'intraday_backfill_settings' => $intradayBackfillScheduler->payload(),
            'intraday_backfill_refresh' => $run ? $intradayDataReloader->refreshPayload($run) : null,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ], 202);
    }

    public function intradayBackfillStatus(
        string $refreshId,
        IntradayCandleBackfillScheduler $intradayBackfillScheduler,
        StockHoldingIntradayDataReloader $intradayDataReloader,
        EodhdApiUsage $eodhdApiUsage,
    ): JsonResponse {
        $run = StockHoldingIntradayReloadRun::query()->find($refreshId);

        if (! $run) {
            return response()->json([
                'message' => 'Intraday backfill not found.',
            ], 404);
        }

        return response()->json([
            'intraday_backfill_settings' => $intradayBackfillScheduler->payload(),
            'intraday_backfill_refresh' => $intradayDataReloader->refreshPayload($run),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }
}
