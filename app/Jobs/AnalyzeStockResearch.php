<?php

namespace App\Jobs;

use App\Services\StockAiResearchService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class AnalyzeStockResearch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 0;

    public int $uniqueFor = 300;

    public function __construct(
        public string $researchId,
        public int $userId,
        public int $stockHoldingId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->stockHoldingId}";
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new RateLimited('stock-ai-research'))->releaseAfter(60),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(StockAiResearchService $researchService): void
    {
        try {
            $researchService->run($this->researchId);
        } catch (RateLimitedException) {
            $retryAfterSeconds = $this->rateLimitBackoff();

            $researchService->markForRetry($this->researchId);

            Log::notice('Stock AI research rate limited; retry scheduled.', [
                'research_id' => $this->researchId,
                'stock_holding_id' => $this->stockHoldingId,
                'attempt' => $this->attempts(),
                'retry_after_seconds' => $retryAfterSeconds,
            ]);

            $this->release($retryAfterSeconds);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(StockAiResearchService::class)->fail($this->researchId);
    }

    private function rateLimitBackoff(): int
    {
        return match (true) {
            $this->attempts() <= 1 => 60,
            $this->attempts() === 2 => 120,
            $this->attempts() === 3 => 300,
            default => 600,
        };
    }
}
