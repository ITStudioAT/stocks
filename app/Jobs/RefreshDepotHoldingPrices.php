<?php

namespace App\Jobs;

use App\Models\StockPriceRefreshRun;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\EodhdBatchRealtimePriceService;
use App\Services\EodhdErrorSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshDepotHoldingPrices implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $refreshId,
        public ?int $recipientUserId = null,
    ) {}

    public function handle(
        EodhdBatchRealtimePriceService $realtimePriceService,
        EodhdErrorSanitizer $errorSanitizer,
        DepotHoldingPriceRefreshProgress $progress,
    ): void {
        $progress->markRunning($this->refreshId);
        $run = StockPriceRefreshRun::query()->find($this->refreshId);
        $run?->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $result = $realtimePriceService->syncAll();
        $status = $this->runStatus($result);
        $errorMessage = $result['errors'] === []
            ? null
            : $errorSanitizer->message(implode(' ', $result['errors']), 1000);

        $progress->finishWithSummary(
            $this->refreshId,
            $result['requested_count'],
            $result['requested_count'],
            $result['message'],
            $status,
            $errorMessage,
        );

        $run?->update([
            'status' => $status,
            'processed_count' => $result['requested_count'],
            'success_count' => $result['requested_count'] - $result['failed_count'],
            'unavailable_count' => $result['failed_count'],
            'finished_at' => now(),
            'error_summary' => $errorMessage === null ? null : [
                'message' => $errorMessage,
            ],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $message = app(EodhdErrorSanitizer::class)->message(
            $exception?->getMessage() ?? 'Unknown queue failure.',
            1000,
        );

        app(DepotHoldingPriceRefreshProgress::class)->fail(
            $this->refreshId,
            $message,
        );

        StockPriceRefreshRun::query()
            ->whereKey($this->refreshId)
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_summary' => ['message' => $message],
            ]);
    }

    /**
     * @param  array{message: string, requested_count: int, stored_count: int, unchanged_count: int, updated_count: int, failed_count: int, errors: array<int, string>}  $result
     */
    private function runStatus(array $result): string
    {
        if ($result['failed_count'] === 0) {
            return 'finished';
        }

        if (($result['stored_count'] + $result['unchanged_count']) > 0) {
            return 'partial';
        }

        return 'failed';
    }
}
