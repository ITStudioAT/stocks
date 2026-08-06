<?php

namespace App\Services;

use App\Jobs\SyncStockEodhdData;
use App\Models\StockEodhdSyncRun;
use App\Models\StockHoldingIntradayReloadRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class V2StockEodhdSyncService
{
    public function __construct(
        private CompletedTradingDay $completedTradingDay,
        private EodhdEndOfDayDataService $endOfDayDataService,
        private StockHoldingIntradayDataReloader $intradayDataReloader,
    ) {}

    public function latestRun(): ?StockEodhdSyncRun
    {
        return StockEodhdSyncRun::query()->latest()->first();
    }

    public function runningRun(): ?StockEodhdSyncRun
    {
        return StockEodhdSyncRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->latest()
            ->first();
    }

    public function createRun(): StockEodhdSyncRun
    {
        $dateTo = $this->completedTradingDay->date()->startOfDay();

        return StockEodhdSyncRun::query()->create([
            'id' => 'stock-eodhd-'.Str::uuid()->toString(),
            'date_from' => $dateTo->copy()->subYear()->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'steps' => [
                ['key' => 'eod', 'label' => 'EOD-Daten', 'status' => 'pending', 'message' => 'Waiting to start.'],
                ['key' => 'intraday', 'label' => 'Intraday-Daten', 'status' => 'pending', 'message' => 'Waiting for EOD data.'],
            ],
            'message' => 'Stock EODHD sync queued.',
        ]);
    }

    public function dispatch(): StockEodhdSyncRun
    {
        return Cache::lock('v2-stock-eodhd-sync-dispatch', 10)->block(5, function (): StockEodhdSyncRun {
            $run = $this->runningRun();

            if ($run) {
                return $run;
            }

            $run = $this->createRun();
            SyncStockEodhdData::dispatch($run->id);

            return $run;
        });
    }

    public function run(string $runId): void
    {
        $run = StockEodhdSyncRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $run->update([
            'status' => 'running',
            'stage' => 'eod',
            'started_at' => $run->started_at ?? now(),
            'current' => 'Synchronizing missing EOD data...',
            'steps' => $this->updateStep($run->steps, 'eod', 'running', 'Synchronizing missing EOD data for all stocks...'),
            'message' => 'EOD data synchronization is running.',
        ]);

        try {
            $this->synchronize($run);
        } catch (Throwable $exception) {
            $this->fail($runId, $exception->getMessage());

            throw $exception;
        }
    }

    public function fail(string $runId, string $message): void
    {
        $run = StockEodhdSyncRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $safeMessage = Str::limit($message, 1000, '');
        $steps = collect($run->steps ?? [])
            ->map(function (array $step) use ($run, $safeMessage): array {
                if ($step['key'] === $run->stage) {
                    return [...$step, 'status' => 'failed', 'message' => $safeMessage];
                }

                if ($step['status'] === 'pending') {
                    return [...$step, 'status' => 'skipped', 'message' => 'Skipped because synchronization stopped.'];
                }

                return $step;
            })
            ->all();

        $run->update([
            'status' => 'failed',
            'current' => null,
            'steps' => $steps,
            'message' => 'Stock EODHD sync failed.',
            'error' => $safeMessage,
            'finished_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(StockEodhdSyncRun $run): array
    {
        $intradayRun = $this->intradayRun($run);
        $intradayTotal = $intradayRun?->total_count ?? $run->intraday_total_count;
        $intradayProcessed = $intradayRun?->processed_count ?? $run->intraday_processed_count;
        $eodCompleted = in_array($this->stepStatus($run, 'eod'), ['finished', 'partial'], true) ? 1 : 0;
        $completed = $eodCompleted + $intradayProcessed;
        $total = 1 + $intradayTotal;

        return [
            'refresh_id' => $run->id,
            'status' => $run->status,
            'stage' => $run->stage,
            'date_from' => $run->date_from?->toDateString(),
            'date_to' => $run->date_to?->toDateString(),
            'current' => $intradayRun?->current ?? $run->current,
            'steps' => $this->payloadSteps($run, $intradayRun),
            'progress' => [
                'completed' => $completed,
                'total' => $total,
                'percent' => $total > 0 ? (int) floor(($completed / $total) * 100) : 0,
            ],
            'eod' => [
                'requested_count' => $run->eod_requested_count,
                'stored_count' => $run->eod_stored_count,
                'skipped_count' => $run->eod_skipped_count,
                'failed_count' => $run->eod_failed_count,
            ],
            'intraday' => [
                'processed_count' => $intradayProcessed,
                'total_count' => $intradayTotal,
                'stored_count' => $intradayRun?->stored_count ?? $run->intraday_stored_count,
                'success_count' => $intradayRun?->success_count ?? $run->intraday_success_count,
                'failed_count' => $intradayRun?->failed_count ?? $run->intraday_failed_count,
                'date_from' => $intradayRun?->date_from?->toDateString(),
                'date_to' => $intradayRun?->date_to?->toDateString(),
            ],
            'message' => $run->message,
            'error' => $run->error,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    private function synchronize(StockEodhdSyncRun $run): void
    {
        $eod = $this->endOfDayDataService->syncAll($run->date_to);
        $eodStatus = $eod['failed_count'] > 0 ? 'partial' : 'finished';
        $run->update([
            'eod_requested_count' => $eod['requested_count'],
            'eod_stored_count' => $eod['stored_count'],
            'eod_skipped_count' => $eod['skipped_count'],
            'eod_failed_count' => $eod['failed_count'],
            'steps' => $this->updateStep(
                $run->steps,
                'eod',
                $eodStatus,
                "{$eod['stored_count']} EOD record(s) loaded/updated; {$eod['failed_count']} stock(s) failed.",
            ),
            'stage' => 'intraday',
            'current' => 'Preparing missing intraday data...',
            'message' => 'Intraday data synchronization is starting.',
        ]);

        $intradayRun = $this->intradayDataReloader->createMissingYearRun($run->date_to);
        $run->update([
            'intraday_reload_run_id' => $intradayRun->id,
            'date_from' => $intradayRun->date_from && $intradayRun->date_from->lt($run->date_from)
                ? $intradayRun->date_from->toDateString()
                : $run->date_from->toDateString(),
            'intraday_total_count' => $intradayRun->total_count,
            'steps' => $this->updateStep($run->steps, 'intraday', 'running', 'Synchronizing missing 5-minute candles for all stocks...'),
            'current' => 'Synchronizing missing intraday data...',
            'message' => 'Intraday data synchronization is running.',
        ]);

        $this->intradayDataReloader->importMissingYear($intradayRun->id);
        $intradayRun->refresh();
        $intradayStatus = in_array($intradayRun->status, ['finished', 'partial'], true)
            ? $intradayRun->status
            : 'failed';
        $run->update([
            'intraday_total_count' => $intradayRun->total_count,
            'intraday_processed_count' => $intradayRun->processed_count,
            'intraday_stored_count' => $intradayRun->stored_count,
            'intraday_success_count' => $intradayRun->success_count,
            'intraday_failed_count' => $intradayRun->failed_count,
            'steps' => $this->updateStep($run->steps, 'intraday', $intradayStatus, $intradayRun->message ?? 'Intraday synchronization finished.'),
            'status' => $run->eod_failed_count > 0 || $intradayRun->failed_count > 0 ? 'partial' : 'finished',
            'current' => null,
            'message' => 'Stock EODHD sync finished.',
            'error' => $this->combinedErrors($eod['errors'], $intradayRun),
            'finished_at' => now(),
        ]);
    }

    private function intradayRun(StockEodhdSyncRun $run): ?StockHoldingIntradayReloadRun
    {
        if (! $run->intraday_reload_run_id) {
            return null;
        }

        return StockHoldingIntradayReloadRun::query()->find($run->intraday_reload_run_id);
    }

    /**
     * @param  array<int, array{key: string, label: string, status: string, message: string}>  $steps
     * @return array<int, array{key: string, label: string, status: string, message: string}>
     */
    private function updateStep(array $steps, string $key, string $status, string $message): array
    {
        return collect($steps)
            ->map(fn (array $step): array => $step['key'] === $key
                ? [...$step, 'status' => $status, 'message' => $message]
                : $step)
            ->all();
    }

    private function stepStatus(StockEodhdSyncRun $run, string $key): ?string
    {
        return collect($run->steps ?? [])->firstWhere('key', $key)['status'] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payloadSteps(StockEodhdSyncRun $run, ?StockHoldingIntradayReloadRun $intradayRun): array
    {
        if (! $intradayRun || $run->stage !== 'intraday' || ! in_array($run->status, ['queued', 'running'], true)) {
            return $run->steps ?? [];
        }

        return $this->updateStep(
            $run->steps,
            'intraday',
            $intradayRun->status === 'queued' ? 'running' : $intradayRun->status,
            $intradayRun->message ?? 'Synchronizing missing intraday data...',
        );
    }

    /**
     * @param  array<int, string>  $eodErrors
     */
    private function combinedErrors(array $eodErrors, StockHoldingIntradayReloadRun $intradayRun): ?string
    {
        $messages = $eodErrors;
        $intradayError = is_array($intradayRun->error_summary)
            ? ($intradayRun->error_summary['message'] ?? null)
            : null;

        if (is_string($intradayError) && $intradayError !== '') {
            $messages[] = $intradayError;
        }

        return $messages === [] ? null : Str::limit(implode(' ', $messages), 1000, '');
    }
}
