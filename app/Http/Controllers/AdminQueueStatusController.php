<?php

namespace App\Http\Controllers;

use App\Jobs\FetchHistoricalSessionPrices;
use App\Jobs\FetchStockHistoricalPrices;
use App\Jobs\RefreshDepotHoldingPrices;
use App\Jobs\ReloadEodhdExchanges;
use App\Jobs\ReloadStockHoldingIntradayData;
use App\Models\StockHistoricalPriceFetchRun;
use App\Models\StockPriceRefreshRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminQueueStatusController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'queue' => $this->queueStatusPayload(),
        ]);
    }

    public function clear(): JsonResponse
    {
        $connection = (string) config('queue.default');
        $queue = (string) config("queue.connections.{$connection}.queue", 'default');
        $clearedJobs = $this->clearQueuedJobs($connection, $queue);
        $clearedFailedJobs = $this->clearFailedJobs();

        return response()->json([
            'message' => "Cleared {$clearedJobs} queued job(s) and {$clearedFailedJobs} failed job record(s).",
            'cleared_jobs' => $clearedJobs,
            'cleared_failed_jobs' => $clearedFailedJobs,
            'queue' => $this->queueStatusPayload(),
        ]);
    }

    /**
     * @return array{status: string, connection: string, name: string, retry_after: int, max_job_timeout: int, pending: ?int, delayed: ?int, reserved: ?int, failed: int, stale_running_refreshes: int, issues: array<int, string>, checked_at: string}
     */
    private function queueStatusPayload(): array
    {
        $connection = (string) config('queue.default');
        $queue = (string) config("queue.connections.{$connection}.queue", 'default');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 0);
        $maxJobTimeout = $this->maxJobTimeout();
        $queueSizes = $this->queueSizes($connection, $queue);
        $failedJobs = $this->failedJobsCount();
        $staleRunningRuns = $this->staleRunningRuns($maxJobTimeout);
        $pendingJobs = (int) ($queueSizes['pending'] ?? 0);
        $reservedJobs = (int) ($queueSizes['reserved'] ?? 0);

        $issues = collect([
            $retryAfter <= $maxJobTimeout
                ? "retry_after ({$retryAfter}s) must be greater than max job timeout ({$maxJobTimeout}s)"
                : null,
            $staleRunningRuns > 0
                ? "{$staleRunningRuns} refresh run(s) are still running after {$maxJobTimeout}s"
                : null,
            $failedJobs > 0
                ? "{$failedJobs} failed job(s) recorded"
                : null,
        ])->filter()->values()->all();
        $status = match (true) {
            $issues !== [] => 'check',
            $pendingJobs > 0 && $reservedJobs === 0 => 'waiting',
            default => 'ok',
        };

        return [
            'status' => $status,
            'connection' => $connection,
            'name' => $queue,
            'retry_after' => $retryAfter,
            'max_job_timeout' => $maxJobTimeout,
            'pending' => $queueSizes['pending'],
            'delayed' => $queueSizes['delayed'],
            'reserved' => $queueSizes['reserved'],
            'failed' => $failedJobs,
            'stale_running_refreshes' => $staleRunningRuns,
            'issues' => $issues,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function maxJobTimeout(): int
    {
        return max([
            (new RefreshDepotHoldingPrices('queue-status-check'))->timeout,
            (new FetchStockHistoricalPrices('queue-status-check'))->timeout,
            (new ReloadEodhdExchanges('queue-status-check'))->timeout,
            (new ReloadStockHoldingIntradayData('queue-status-check'))->timeout,
            (new FetchHistoricalSessionPrices('queue-status-check', [], [
                'timezone' => 'UTC',
                'today_date' => '2026-06-05',
                'today_open' => '2026-06-05T08:00:00+00:00',
                'today_close' => '2026-06-05T16:30:00+00:00',
                'previous_date' => '2026-06-04',
                'previous_open' => '2026-06-04T08:00:00+00:00',
                'previous_close' => '2026-06-04T16:30:00+00:00',
                'two_ago_date' => '2026-06-03',
                'two_ago_open' => '2026-06-03T08:00:00+00:00',
                'two_ago_close' => '2026-06-03T16:30:00+00:00',
            ]))->timeout,
        ]);
    }

    /**
     * @return array{pending: ?int, delayed: ?int, reserved: ?int}
     */
    private function queueSizes(string $connection, string $queue): array
    {
        try {
            $queueConnection = Queue::connection($connection);

            return [
                'pending' => method_exists($queueConnection, 'pendingSize') ? $queueConnection->pendingSize($queue) : $queueConnection->size($queue),
                'delayed' => method_exists($queueConnection, 'delayedSize') ? $queueConnection->delayedSize($queue) : null,
                'reserved' => method_exists($queueConnection, 'reservedSize') ? $queueConnection->reservedSize($queue) : null,
            ];
        } catch (Throwable) {
            return [
                'pending' => null,
                'delayed' => null,
                'reserved' => null,
            ];
        }
    }

    private function failedJobsCount(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return DB::table('failed_jobs')->count();
    }

    private function clearQueuedJobs(string $connection, string $queue): int
    {
        $queueConnection = Queue::connection($connection);

        if (! method_exists($queueConnection, 'clear')) {
            return 0;
        }

        return (int) $queueConnection->clear($queue);
    }

    private function clearFailedJobs(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return DB::table('failed_jobs')->delete();
    }

    private function staleRunningRuns(int $maxJobTimeout): int
    {
        $staleBefore = Carbon::now()->subSeconds($maxJobTimeout);

        return StockPriceRefreshRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->where(function ($query) use ($staleBefore): void {
                $query
                    ->where('started_at', '<', $staleBefore)
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query
                            ->whereNull('started_at')
                            ->where('created_at', '<', $staleBefore);
                    });
            })
            ->count()
            + StockHistoricalPriceFetchRun::query()
                ->whereIn('status', ['queued', 'running'])
                ->where(function ($query) use ($staleBefore): void {
                    $query
                        ->where('started_at', '<', $staleBefore)
                        ->orWhere(function ($query) use ($staleBefore): void {
                            $query
                                ->whereNull('started_at')
                                ->where('created_at', '<', $staleBefore);
                        });
                })
                ->count();
    }
}
